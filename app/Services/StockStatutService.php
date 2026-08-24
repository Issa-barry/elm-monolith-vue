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
 * Règle métier actée pour ELM :
 *   - le SEUIL est configuré au niveau PRODUIT (produits.seuil_alerte_stock, repli sur
 *     parametres.seuil_stock_faible de l'organisation si null) et s'applique uniformément à
 *     toutes les variantes du produit ;
 *   - la QUANTITE vit au niveau VARIANTE × SITE (variante_stocks.qte_stock) ;
 *   - l'ETAT est calculé pour chaque couple VARIANTE × SITE, jamais sur un total agrégé — un
 *     stock élevé sur une variante ou un site ne doit jamais masquer un stock faible ailleurs.
 *
 * RUPTURE est toujours calculée dès lors que le type du produit gère du stock, indépendamment
 * du choix "être alerté si stock faible" : c'est un fait de disponibilité (comme "Épuisé" chez
 * Shopify), pas une préférence de notification. STOCK_FAIBLE n'est calculée que si
 * `produit.alerte_stock_active` est vrai — c'est le choix explicite de l'utilisateur, jamais
 * une case cochée automatiquement.
 */
class StockStatutService
{
    public function seuilEffectif(Produit $produit): int
    {
        return $produit->seuil_alerte_stock ?? Parametre::getSeuilStockFaible((string) $produit->organization_id);
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

        return $this->statutPour($varianteStock->qte_stock, $this->seuilEffectif($produit), (bool) $produit->alerte_stock_active);
    }

    /**
     * Détail variante × site pour un produit — nécessite $produit chargé avec
     * ['variantes.stocks']. Utilisé par les pages Show/Index pour afficher où se trouve
     * précisément le problème (cf. décision : jamais masquer une alerte locale derrière un
     * total agrégé).
     *
     * @return Collection<int, array{variante_id: string, variante_libelle: string, site_id: string, qte_stock: int, statut: string, statut_label: string}>
     */
    public function detailParVarianteEtSite(Produit $produit): Collection
    {
        $seuil = $this->seuilEffectif($produit);
        $alerteActive = (bool) $produit->alerte_stock_active;
        $gereStock = (bool) $produit->produitType?->gere_stock;

        return $produit->variantes->flatMap(
            fn ($variante) => $variante->stocks->map(function (VarianteStock $vs) use ($variante, $seuil, $alerteActive, $gereStock) {
                $statut = $gereStock ? $this->statutPour($vs->qte_stock, $seuil, $alerteActive) : StockStatut::DISPONIBLE;

                return [
                    'variante_id' => $variante->id,
                    'variante_libelle' => $variante->libelle,
                    'site_id' => $vs->site_id,
                    'qte_stock' => $vs->qte_stock,
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
            ->where('p.organization_id', $organizationId)
            ->where('p.statut', '!=', 'archive')
            ->where('pt.gere_stock', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->select('vs.qte_stock', 'p.seuil_alerte_stock', 'p.alerte_stock_active')
            ->get();

        $ruptures = 0;
        $faibles = 0;
        foreach ($rows as $row) {
            if ($row->qte_stock <= 0) {
                $ruptures++;

                continue;
            }
            $seuil = $row->seuil_alerte_stock ?? $seuilOrg;
            if ($row->alerte_stock_active && $seuil > 0 && $row->qte_stock <= $seuil) {
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
     *  (a) au moins une variante d'un produit ACTIF, vendable, géré en stock, a une quantité
     *      STRICTEMENT positive sur ce site ; ou
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
            ->where('vs.qte_stock', '>', 0)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->exists();
    }
}
