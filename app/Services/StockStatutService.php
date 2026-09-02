<?php

namespace App\Services;

use App\Enums\ProduitStatut;
use App\Enums\StockStatut;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\VarianteStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Source UNIQUE de la règle "état de stock" — remplace les implémentations dupliquées
 * constatées avant refonte (Produit::getIsLowStockAttribute() d'un côté, logique réécrite
 * inline dans ProduitController@index de l'autre, avec des résultats parfois différents).
 *
 * Règle métier actée pour ELM (revue le 01/09/2026 — actif ET seuil par SITE, cf.
 * ProduitSeuilAlerte) :
 *   - l'ACTIVATION ("être alerté si stock faible ?") ET le SEUIL sont configurés par COUPLE
 *     (produit, site) — table produit_seuils_alerte. Absence de ligne pour un site = alerte
 *     INACTIVE sur ce site, jamais implicite : un produit non concerné par un site (ex. non
 *     vendu dans cette agence) ne génère aucune alerte tant qu'un administrateur ne l'a pas
 *     explicitement activée pour CE site. Le seuil, lui, replie sur parametres.seuil_stock_faible
 *     de l'organisation si aucune valeur spécifique n'est renseignée sur une ligne active.
 *     Les anciennes colonnes produits.alerte_stock_active/seuil_alerte_stock (choix global
 *     appliqué à tous les sites) sont conservées en base à titre historique mais ne sont plus
 *     jamais lues ici ;
 *   - la QUANTITE vit au niveau VARIANTE × SITE (variante_stocks.qte_stock) ;
 *   - l'ETAT est calculé pour chaque couple VARIANTE × SITE, avec l'activation ET le seuil DE CE
 *     SITE, jamais sur un total agrégé ni la configuration d'un autre site — un stock élevé sur
 *     une variante ou un site ne doit jamais masquer un stock faible ailleurs, et Matoto ne doit
 *     jamais servir à contrôler CBA.
 *
 * RUPTURE est toujours calculée dès lors que le type du produit gère du stock, indépendamment
 * du choix "être alerté si stock faible" : c'est un fait de disponibilité (comme "Épuisé" chez
 * Shopify), pas une préférence de notification. STOCK_FAIBLE n'est calculée que si l'alerte est
 * active POUR CE SITE — c'est le choix explicite de l'utilisateur, jamais une case cochée
 * automatiquement.
 */
class StockStatutService
{
    /**
     * Seuil effectif d'un produit POUR UN SITE donné : seuil spécifique produit/site s'il existe,
     * sinon seuil global de l'organisation. Ne lit jamais le seuil d'un autre site. Reste
     * calculable même si le site n'a pas l'alerte active (utile pour l'affichage) — c'est
     * alerteActivePourSite() qui gouverne si ce seuil est effectivement exploité.
     */
    public function seuilEffectifPourSite(Produit $produit, string $siteId): int
    {
        $seuils = $produit->relationLoaded('seuilsAlerte')
            ? $produit->seuilsAlerte
            : $produit->seuilsAlerte()->get();

        $specifique = $seuils->firstWhere('site_id', $siteId)?->seuil_alerte_stock;

        return $specifique ?? Parametre::getSeuilStockFaible((string) $produit->organization_id);
    }

    /**
     * L'alerte de stock faible est-elle active pour ce produit SUR CE SITE ? Absence de ligne
     * produit_seuils_alerte pour ce couple = inactive, jamais implicitement active (cf. docblock
     * de la classe) — ne lit jamais l'activation d'un autre site.
     */
    public function alerteActivePourSite(Produit $produit, string $siteId): bool
    {
        $seuils = $produit->relationLoaded('seuilsAlerte')
            ? $produit->seuilsAlerte
            : $produit->seuilsAlerte()->get();

        return (bool) ($seuils->firstWhere('site_id', $siteId)?->actif ?? false);
    }

    public function statutPour(int $qte, int $seuil, bool $alerteActive): StockStatut
    {
        if ($qte < 0) {
            return StockStatut::STOCK_NEGATIF;
        }

        if ($qte === 0) {
            return StockStatut::RUPTURE;
        }

        if ($alerteActive && $seuil > 0 && $qte <= $seuil) {
            return StockStatut::STOCK_FAIBLE;
        }

        return StockStatut::DISPONIBLE;
    }

    public function statutPourVarianteStock(Produit $produit, VarianteStock $varianteStock): StockStatut
    {
        if (! $produit->produitType?->gere_stock) {
            return StockStatut::DISPONIBLE;
        }

        // Disponible = physique − engagé (StockReservationService, 25/08/2026) : un stock
        // physique positif mais entièrement engagé par des commandes confirmées n'est plus
        // vendable, jamais affiché "Disponible" (même règle que StockController::stockQuery()).
        $disponible = $varianteStock->qte_stock - $varianteStock->qte_reservee;
        $seuil = $this->seuilEffectifPourSite($produit, $varianteStock->site_id);
        $alerteActive = $this->alerteActivePourSite($produit, $varianteStock->site_id);

        return $this->statutPour($disponible, $seuil, $alerteActive);
    }

    /**
     * Détail variante × site pour un produit — nécessite $produit chargé avec
     * ['variantes.stocks', 'seuilsAlerte']. Utilisé par les pages Show/Index pour afficher où se
     * trouve précisément le problème (cf. décision : jamais masquer une alerte locale derrière un
     * total agrégé). Le seuil est résolu PAR SITE (cf. seuilEffectifPourSite()) : deux sites du
     * même produit peuvent légitimement afficher des états différents pour la même quantité.
     *
     * @return Collection<int, array{variante_id: string, variante_libelle: string, site_id: string, qte_stock: int, qte_reservee: int, qte_disponible: int, seuil_effectif: int, statut: string, statut_label: string}>
     */
    public function detailParVarianteEtSite(Produit $produit): Collection
    {
        $gereStock = (bool) $produit->produitType?->gere_stock;

        return $produit->variantes->flatMap(
            fn ($variante) => $variante->stocks->map(function (VarianteStock $vs) use ($variante, $produit, $gereStock) {
                // Disponible = physique − engagé (cf. statutPourVarianteStock()) : l'État se
                // base toujours sur cette quantité, jamais le physique brut qte_stock (conservé
                // ci-dessous pour l'affichage détaillé, mais plus pour le calcul de l'état).
                $disponible = $vs->qte_stock - $vs->qte_reservee;
                $seuil = $this->seuilEffectifPourSite($produit, $vs->site_id);
                $alerteActive = $this->alerteActivePourSite($produit, $vs->site_id);
                $statut = $gereStock ? $this->statutPour($disponible, $seuil, $alerteActive) : StockStatut::DISPONIBLE;

                return [
                    'variante_id' => $variante->id,
                    'variante_libelle' => $variante->libelle,
                    'site_id' => $vs->site_id,
                    'qte_stock' => $vs->qte_stock,
                    'qte_reservee' => $vs->qte_reservee,
                    'qte_disponible' => $disponible,
                    'seuil_effectif' => $seuil,
                    'statut' => $statut->value,
                    'statut_label' => $statut->label(),
                ];
            })
        );
    }

    /**
     * Nombre de couples variante × site actuellement en alerte (stock faible ou rupture) pour
     * un produit — alimente le badge synthétique "N alerte(s) de stock" des écrans Index/Show.
     * Une même variante en alerte sur 2 sites compte pour 2, volontairement : ce sont deux
     * problèmes locaux distincts à traiter (ex: réassort ciblé par site), pas une seule alerte.
     */
    public function nombreAlertesPourProduit(Produit $produit): int
    {
        return $this->detailParVarianteEtSite($produit)
            ->filter(fn (array $d) => $d['statut'] !== StockStatut::DISPONIBLE->value)
            ->count();
    }

    /**
     * Compteur global (badge sidebar) — une seule requête agrégée sur toute l'organisation,
     * volontairement en SQL brut plutôt que via des accesseurs Eloquent par produit : ce calcul
     * s'exécute à chaque chargement de page (middleware Inertia partagé), un N+1 par produit
     * serait inacceptable en performance.
     *
     * @return array{ruptures: int, faibles: int, total: int}
     */
    public function compterAlertesPourOrganisation(string $organizationId): array
    {
        $seuilOrg = Parametre::getSeuilStockFaible($organizationId);

        $rows = DB::table('variante_stocks as vs')
            ->join('produit_variantes as pv', 'pv.id', '=', 'vs.produit_variante_id')
            ->join('produits as p', 'p.id', '=', 'pv.produit_id')
            ->join('produit_types as pt', 'pt.id', '=', 'p.produit_type_id')
            ->leftJoin('produit_seuils_alerte as psa', function ($join) {
                $join->on('psa.produit_id', '=', 'p.id')->on('psa.site_id', '=', 'vs.site_id');
            })
            ->where('p.organization_id', $organizationId)
            ->where('p.statut', '!=', 'archive')
            ->where('pt.gere_stock', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->select('psa.seuil_alerte_stock as seuil_specifique', 'psa.actif as site_alerte_active')
            ->selectRaw('(vs.qte_stock - COALESCE(vs.qte_reservee, 0)) as qte_disponible')
            ->get();

        $ruptures = 0;
        $faibles = 0;
        foreach ($rows as $row) {
            // Disponible = physique − engagé (StockReservationService, 25/08/2026) : le badge
            // sidebar comptait auparavant uniquement le physique brut, masquant les ruptures
            // réelles d'un stock entièrement engagé par des commandes confirmées.
            if ($row->qte_disponible <= 0) {
                $ruptures++;

                continue;
            }
            // Activation ET seuil du SITE de cette ligne (psa filtré sur vs.site_id ci-dessus),
            // jamais ceux d'un autre site du même produit. Absence de ligne (leftJoin) = alerte
            // inactive sur ce site, jamais implicitement active.
            $seuil = $row->seuil_specifique ?? $seuilOrg;
            if ($row->site_alerte_active && $seuil > 0 && $row->qte_disponible <= $seuil) {
                $faibles++;
            }
        }

        return ['ruptures' => $ruptures, 'faibles' => $faibles, 'total' => $ruptures + $faibles];
    }

    /**
     * Le site a-t-il au moins UN produit réellement vendable, maintenant — utilisé UNIQUEMENT
     * pour décider si la création d'une nouvelle commande vente doit être bloquée quand la
     * politique globale interdit la vente sans stock (cf. Parametre::
     * isVentesAutoriseesSansStock(), CommandeVenteService::siteAutoriseNouvelleCommande()).
     * Volontairement une EXISTENCE, jamais une somme de quantités (remplace l'ancienne
     * stockTotalVendableSite() : un produit à +5 et un autre à -5 sur le même site donnerait une
     * somme nulle alors qu'un produit est bel et bien vendable — une quantité négative isolée ne
     * doit, à l'inverse, jamais suffire à elle seule à autoriser une création). C'est un signal
     * volontairement grossier ("ce site a-t-il quelque chose à vendre ?"), pas une garantie que
     * chaque variante précise sera disponible — le contrôle fin reste fait ligne par ligne au
     * moment réel de la vente (PDV, création/modification de commande, chargement).
     *
     * Vrai si :
     *  (a) au moins une variante d'un produit ACTIF, vendable, géré en stock, a un DISPONIBLE
     *      (physique − engagé, cf. StockReservationService) STRICTEMENT positif sur ce site ; ou
     *  (b) au moins un produit ACTIF, vendable, qui NE gère PAS de stock (type "service")
     *      existe dans l'organisation — vendable indépendamment de tout stock physique sur ce
     *      site, même convention que verifierDisponibiliteLignes()/produitsActifs(), qui
     *      ignorent déjà ces lignes dans le contrôle de disponibilité.
     */
    public function sitePossedeStockVendable(string $organizationId, string $siteId): bool
    {
        $existeServiceVendableSansStock = Produit::where('organization_id', $organizationId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereHas('produitType', fn ($q) => $q->where('vendable', true)->where('gere_stock', false))
            ->exists();

        if ($existeServiceVendableSansStock) {
            return true;
        }

        return DB::table('variante_stocks as vs')
            ->join('produit_variantes as pv', 'pv.id', '=', 'vs.produit_variante_id')
            ->join('produits as p', 'p.id', '=', 'pv.produit_id')
            ->join('produit_types as pt', 'pt.id', '=', 'p.produit_type_id')
            ->where('p.organization_id', $organizationId)
            ->where('vs.site_id', $siteId)
            ->where('p.statut', ProduitStatut::ACTIF->value)
            ->where('pt.gere_stock', true)
            ->where('pt.vendable', true)
            ->whereRaw('(vs.qte_stock - COALESCE(vs.qte_reservee, 0)) > 0')
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->exists();
    }
}
