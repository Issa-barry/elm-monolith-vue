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
 * Règle métier actée pour ELM, revue le 02/09/2026 après-midi — DISPONIBILITÉ et ALERTE sont
 * deux notions INDÉPENDANTES, jamais mélangées dans le calcul de l'état lui-même (correctif
 * d'une confusion introduite puis corrigée le jour même : un site sans alerte active ne doit
 * JAMAIS voir son état physique remplacé artificiellement par "Disponible") :
 *
 *   - statutPour()/statutPourVarianteStock() sont des fonctions PURES : seules la quantité et le
 *     seuil entrent en jeu. Le stock physique reste réel, toujours — jamais transformé pour
 *     refléter une préférence de configuration ;
 *   - `disponible` (produit_seuils_alerte) — DISPONIBILITÉ : ce produit est-il vendu/géré sur ce
 *     site ? Défaut TRUE (disponible partout tant qu'aucune restriction explicite n'existe,
 *     mode "Tous les sites"). Un site non disponible n'a AUCUNE rupture "métier" possible — son
 *     état réel n'est simplement jamais pertinent à afficher comme une alerte, quel que soit son
 *     stock physique (cf. disponiblePourSite()) ;
 *   - `actif` (produit_seuils_alerte) — ALERTE : faut-il surveiller/notifier ce couple ? Défaut
 *     FALSE, jamais implicite (cf. alerteActivePourSite()). Un site DISPONIBLE mais sans alerte
 *     affiche quand même son état RÉEL (ex: Rupture) dans les vues opérationnelles (Stock, fiche
 *     produit) — seule la notification/le comptage d'alerte sont supprimés, jamais l'affichage.
 *   - le SEUIL est configuré par COUPLE (produit, site) — repli sur parametres.seuil_stock_faible
 *     de l'organisation si aucune valeur spécifique n'est renseignée.
 *
 * Ces trois axes (disponibilité, alerte, seuil) sont indépendants et se combinent au niveau des
 * APPELANTS (badges d'alerte, notifications, filtres), jamais à l'intérieur de statutPour().
 *
 * La QUANTITE vit au niveau VARIANTE × SITE (variante_stocks.qte_stock). L'ETAT est calculé pour
 * chaque couple VARIANTE × SITE, avec le seuil DE CE SITE, jamais sur un total agrégé ni la
 * configuration d'un autre site — un stock élevé sur une variante ou un site ne doit jamais
 * masquer un stock faible ailleurs, et Matoto ne doit jamais servir à contrôler CBA.
 */
class StockStatutService
{
    /**
     * Seuil effectif d'un produit POUR UN SITE donné : seuil spécifique produit/site s'il existe,
     * sinon seuil global de l'organisation. Ne lit jamais le seuil d'un autre site.
     */
    public function seuilEffectifPourSite(Produit $produit, string $siteId): int
    {
        $specifique = $this->ligneSeuilsAlerte($produit, $siteId)?->seuil_alerte_stock;

        return $specifique ?? Parametre::getSeuilStockFaible((string) $produit->organization_id);
    }

    /**
     * Ce produit est-il DISPONIBLE (vendu/géré) sur ce site ? Défaut TRUE — absence de ligne ou
     * de restriction explicite = disponible PARTOUT (mode "Tous les sites"), à l'inverse de
     * l'alerte qui défaut à FALSE. Un site non disponible ne doit jamais être traité comme étant
     * en rupture "métier", quel que soit son stock physique.
     */
    public function disponiblePourSite(Produit $produit, string $siteId): bool
    {
        return (bool) ($this->ligneSeuilsAlerte($produit, $siteId)?->disponible ?? true);
    }

    /**
     * L'alerte de stock faible est-elle active pour ce produit SUR CE SITE ? Absence de ligne
     * produit_seuils_alerte pour ce couple = inactive, jamais implicitement active — ne lit
     * jamais l'activation d'un autre site.
     */
    public function alerteActivePourSite(Produit $produit, string $siteId): bool
    {
        return (bool) ($this->ligneSeuilsAlerte($produit, $siteId)?->actif ?? false);
    }

    private function ligneSeuilsAlerte(Produit $produit, string $siteId): ?object
    {
        $seuils = $produit->relationLoaded('seuilsAlerte')
            ? $produit->seuilsAlerte
            : $produit->seuilsAlerte()->get();

        return $seuils->firstWhere('site_id', $siteId);
    }

    /**
     * Règle PURE : uniquement la quantité et le seuil. Ne connaît ni la disponibilité ni
     * l'alerte — c'est aux appelants de décider si cet état doit déclencher une notification/un
     * comptage (cf. docblock de la classe). Le stock physique reste réel, toujours.
     */
    public function statutPour(int $qte, int $seuil): StockStatut
    {
        if ($qte < 0) {
            return StockStatut::STOCK_NEGATIF;
        }

        if ($qte === 0) {
            return StockStatut::RUPTURE;
        }

        if ($seuil > 0 && $qte <= $seuil) {
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

        return $this->statutPour($disponible, $seuil);
    }

    /**
     * Détail variante × site pour un produit — nécessite $produit chargé avec
     * ['variantes.stocks', 'seuilsAlerte']. Utilisé par les pages Show/Index pour afficher où se
     * trouve précisément le problème (cf. décision : jamais masquer une alerte locale derrière un
     * total agrégé). Le seuil est résolu PAR SITE (cf. seuilEffectifPourSite()) : deux sites du
     * même produit peuvent légitimement afficher des états différents pour la même quantité.
     *
     * `statut` reste TOUJOURS l'état physique réel (cf. statutPour(), fonction pure) — jamais
     * masqué par la disponibilité ou l'alerte. `disponible_sur_site`/`alerte_active` sont exposés
     * séparément pour que les appelants (frontend compris) décident de l'affichage/comptage :
     * un site non disponible n'a pas de rupture "métier" à afficher (le frontend l'indique par
     * "Non disponible" plutôt que par le statut coloré) ; un site sans alerte affiche l'état réel
     * mais ne doit compter dans aucun badge/notification.
     *
     * @return Collection<int, array{variante_id: string, variante_libelle: string, site_id: string, qte_stock: int, qte_reservee: int, qte_disponible: int, seuil_effectif: int, disponible_sur_site: bool, alerte_active: bool, statut: string, statut_label: string}>
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
                $disponibleSurSite = $this->disponiblePourSite($produit, $vs->site_id);
                $alerteActive = $this->alerteActivePourSite($produit, $vs->site_id);
                $statut = $gereStock ? $this->statutPour($disponible, $seuil) : StockStatut::DISPONIBLE;

                return [
                    'variante_id' => $variante->id,
                    'variante_libelle' => $variante->libelle,
                    'site_id' => $vs->site_id,
                    'qte_stock' => $vs->qte_stock,
                    'qte_reservee' => $vs->qte_reservee,
                    'qte_disponible' => $disponible,
                    'seuil_effectif' => $seuil,
                    'disponible_sur_site' => $disponibleSurSite,
                    'alerte_active' => $alerteActive,
                    'statut' => $statut->value,
                    'statut_label' => $statut->label(),
                ];
            })
        );
    }

    /**
     * Nombre de couples variante × site actuellement EN ALERTE (stock faible ou rupture,
     * disponible ET alerte active) pour un produit — alimente le badge synthétique "N alerte(s)
     * de stock" des écrans Index/Show. Un site non disponible ou sans alerte active ne compte
     * jamais, même en rupture physique réelle. Une même variante en alerte sur 2 sites compte
     * pour 2, volontairement : ce sont deux problèmes locaux distincts à traiter (ex: réassort
     * ciblé par site), pas une seule alerte.
     */
    public function nombreAlertesPourProduit(Produit $produit): int
    {
        return $this->detailParVarianteEtSite($produit)
            ->filter(fn (array $d) => $d['disponible_sur_site'] && $d['alerte_active'] && $d['statut'] !== StockStatut::DISPONIBLE->value)
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
            ->select(
                'psa.seuil_alerte_stock as seuil_specifique',
                'psa.actif as site_alerte_active',
                'psa.disponible as site_disponible',
            )
            ->selectRaw('(vs.qte_stock - COALESCE(vs.qte_reservee, 0)) as qte_disponible')
            ->get();

        $ruptures = 0;
        $faibles = 0;
        foreach ($rows as $row) {
            // Absence de ligne (leftJoin) = disponible par défaut (psa.disponible NULL → true),
            // mais alerte INACTIVE par défaut (psa.actif NULL → false) — cf. docblock de la
            // classe. Un badge d'alerte n'a de sens que si le site est à la fois disponible ET
            // surveillé, quel que soit son stock physique réel.
            $disponible = $row->site_disponible === null ? true : (bool) $row->site_disponible;
            if (! $disponible || ! $row->site_alerte_active) {
                continue;
            }
            // Disponible = physique − engagé (StockReservationService, 25/08/2026) : le badge
            // sidebar comptait auparavant uniquement le physique brut, masquant les ruptures
            // réelles d'un stock entièrement engagé par des commandes confirmées.
            if ($row->qte_disponible <= 0) {
                $ruptures++;

                continue;
            }
            // Seuil du SITE de cette ligne (psa filtré sur vs.site_id ci-dessus), jamais celui
            // d'un autre site du même produit.
            $seuil = $row->seuil_specifique ?? $seuilOrg;
            if ($seuil > 0 && $row->qte_disponible <= $seuil) {
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
