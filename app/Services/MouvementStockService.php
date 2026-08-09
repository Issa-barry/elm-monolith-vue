<?php

namespace App\Services;

use App\Models\MouvementStock;
use App\Models\ProduitVariante;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\VarianteStock;
use Illuminate\Support\Facades\Auth;

/**
 * Gestion idempotente des mouvements de stock liés aux transferts inter-sites.
 *
 * Timing :
 *  - Sortie source  → déclenché au passage en RECEPTION (produits quittés le site source)
 *  - Entrée destination → déclenché au passage en CLOTURE (produits comptés à destination)
 */
class MouvementStockService
{
    /**
     * Enregistre la sortie de stock du site source.
     * Idempotent : ne crée pas de doublon si déjà enregistré pour cette ligne.
     */
    public static function enregistrerSortieSource(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            $quantite = $ligne->quantite_chargee ?? $ligne->quantite_demandee;

            self::creerSiAbsent([
                'organization_id' => $transfert->organization_id,
                'site_id' => $transfert->site_source_id,
                'produit_variante_id' => $ligne->variante_id,
                'type' => 'sortie',
                'quantite' => $quantite,
                'source_type' => TransfertLigne::class,
                'source_id' => $ligne->id,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Enregistre l'entrée de stock au site destination.
     * Utilise quantite_recue (données réelles de réception).
     * Idempotent.
     */
    public static function enregistrerEntreeDestination(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            // On comptabilise ce qui a réellement été reçu
            $quantite = $ligne->quantite_recue ?? $ligne->quantite_chargee ?? $ligne->quantite_demandee;

            self::creerSiAbsent([
                'organization_id' => $transfert->organization_id,
                'site_id' => $transfert->site_destination_id,
                'produit_variante_id' => $ligne->variante_id,
                'type' => 'entree',
                'quantite' => $quantite,
                'source_type' => TransfertLigne::class,
                'source_id' => $ligne->id,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Supprime les entrées de stock destination créées lors de la réception.
     * Appelé quand un admin invalide une réception pour la renvoyer en transit.
     */
    public static function supprimerEntreeDestination(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');

        foreach ($transfert->lignes as $ligne) {
            MouvementStock::where('source_type', TransfertLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $transfert->site_destination_id)
                ->where('type', 'entree')
                ->delete();
        }
    }

    /**
     * Sortie de stock générique pour une variante sur un site donné : décrémente
     * VarianteStock (borné à 0), resynchronise l'agrégat Produit::qte_stock (rollup
     * sur toutes les variantes/sites), et trace un MouvementStock — idempotent sur
     * (source_type, source_id, site_id, type).
     *
     * Au tout premier mouvement pour une variante sur un site, migre le stock
     * global existant (Produit::qte_stock) plutôt que de repartir de 0 —
     * sinon la création de la ligne « adopte » un stock à 0 et écrase
     * l'agrégat au recalcul (cf. CommandeVenteService::decrementerStock() et
     * PdvCheckoutService, qui partagent cette méthode).
     *
     * Utilisé par CommandeVenteService (chargement logistique) et
     * PdvCheckoutService (vente comptoir) — pas par les transferts inter-sites,
     * qui suivent un timing différent (sortie source / entrée destination,
     * voir enregistrerSortieSource()/enregistrerEntreeDestination() ci-dessus).
     */
    public static function sortirStock(
        string $varianteId,
        string $siteId,
        string $orgId,
        int $quantite,
        string $sourceType,
        string $sourceId,
        ?string $userId,
    ): void {
        if ($quantite <= 0) {
            return;
        }

        $dejaTraite = MouvementStock::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('site_id', $siteId)
            ->where('type', 'sortie')
            ->exists();

        if ($dejaTraite) {
            return;
        }

        $aucuneLigneStockSite = ! VarianteStock::where('produit_variante_id', $varianteId)->exists();
        $varianteStock = VarianteStock::firstOrCreate(
            ['produit_variante_id' => $varianteId, 'site_id' => $siteId],
            [
                'organization_id' => $orgId,
                'qte_stock' => $aucuneLigneStockSite ? self::qteStockProduitParent($varianteId) : 0,
            ]
        );

        $stockAvant = $varianteStock->qte_stock;
        $stockApres = max(0, $stockAvant - $quantite);
        $varianteStock->update(['qte_stock' => $stockApres]);

        ProduitVariante::find($varianteId)?->produit?->resynchroniserQteStock();

        MouvementStock::create([
            'organization_id' => $orgId,
            'site_id' => $siteId,
            'produit_variante_id' => $varianteId,
            'type' => 'sortie',
            'quantite' => $quantite,
            'stock_avant' => $stockAvant,
            'stock_apres' => $stockApres,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $userId,
        ]);
    }

    /**
     * Quantité disponible pour une variante sur un site donné, en tenant compte
     * de la migration du stock legacy (cf. sortirStock()) : si aucune ligne
     * VarianteStock n'existe encore pour cette variante (aucun site), on retombe
     * sur l'agrégat global du produit parent plutôt que de considérer 0 disponible.
     */
    public static function quantiteDisponible(string $varianteId, string $siteId): int
    {
        $stock = VarianteStock::where('produit_variante_id', $varianteId)->where('site_id', $siteId)->first();
        if ($stock) {
            return $stock->qte_stock;
        }

        if (VarianteStock::where('produit_variante_id', $varianteId)->exists()) {
            return 0;
        }

        return self::qteStockProduitParent($varianteId);
    }

    private static function qteStockProduitParent(string $varianteId): int
    {
        return (int) (ProduitVariante::find($varianteId)?->produit?->qte_stock ?? 0);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Insère un mouvement uniquement s'il n'existe pas déjà
     * (unicité sur source_type + source_id + site_id + type).
     */
    private static function creerSiAbsent(array $data): void
    {
        $existe = MouvementStock::where('source_type', $data['source_type'])
            ->where('source_id', $data['source_id'])
            ->where('site_id', $data['site_id'])
            ->where('type', $data['type'])
            ->exists();

        if (! $existe) {
            MouvementStock::create($data);
        }
    }
}
