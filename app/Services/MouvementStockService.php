<?php

namespace App\Services;

use App\Models\MouvementStock;
use App\Models\ProduitVariante;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\VarianteStock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des mouvements de stock, ventilés par site. Point d'entrée unique
 * (appliquer()) pour toute mutation de VarianteStock, afin qu'un seul chemin de
 * calcul (stock_avant/stock_apres, resynchronisation de l'agrégat produit,
 * traçabilité MouvementStock) existe dans toute l'application.
 *
 * Règle invariante : une ligne VarianteStock nouvellement créée démarre TOUJOURS
 * à 0. Le stock legacy (Produit::qte_stock, hérité de l'ancien modèle mono-stock
 * sans notion de site) n'est jamais recopié implicitement sur le premier site
 * touché — quel que soit le flux (ajustement manuel, vente, réception, transfert).
 * L'ordre dans lequel les agences sont touchées ne doit jamais décider de quelle
 * agence « possède » un stock historique non ventilé.
 */
class MouvementStockService
{
    /**
     * Primitive unique de mutation du stock par site. Verrouille (lockForUpdate)
     * la ligne VarianteStock concernée — tous les appelants s'exécutent déjà dans
     * une DB::transaction — l'initialise à 0 si absente, applique le delta,
     * resynchronise Produit::qte_stock, et trace un MouvementStock avec
     * stock_avant/stock_apres complets.
     *
     * $allowNegative gouverne ce qui se passe quand une sortie dépasse le stock
     * disponible : soit elle est refusée EN ENTIER avant toute écriture (défaut —
     * aucun mouvement créé, stock inchangé), soit elle est appliquée EN ENTIER,
     * quitte à faire passer le stock sous 0 (cf. Produit::autorise_vente_stock_negatif).
     * Il n'existe plus de troisième voie : jamais de clamp silencieux à 0 qui
     * n'appliquerait qu'une partie du delta demandé (cf. audit stock du 23/08/2026 —
     * un mouvement dont le calcul stock_avant/delta/stock_apres ne correspond plus
     * à la réalité rend le journal non réconciliable). Le contrôle est fait ICI,
     * sous le verrou — jamais seulement par un pré-contrôle dans l'appelant, qui
     * laisserait une fenêtre de concurrence (cf. faille TOCTOU relevée sur
     * ProduitController::ajusterStock() avant ce correctif) ou pourrait être
     * contourné par un appel direct au service.
     *
     * @throws ValidationException si la sortie dépasse le disponible et $allowNegative est faux
     */
    public static function appliquer(
        string $varianteId,
        string $siteId,
        string $orgId,
        string $type, // 'entree' | 'sortie'
        int $quantite,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $userId = null,
        ?string $notes = null,
        bool $allowNegative = false,
    ): MouvementStock {
        $varianteStock = VarianteStock::where('produit_variante_id', $varianteId)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->first();

        $stockAvant = $varianteStock?->qte_stock ?? 0;
        $delta = $type === 'entree' ? $quantite : -$quantite;
        $stockApres = $stockAvant + $delta;

        // Contrôle AVANT toute écriture : un refus ne doit laisser AUCUNE trace, pas même une
        // ligne VarianteStock nouvellement matérialisée à 0 pour une variante/site qui n'en
        // avait encore aucune — sinon un refus aurait le même effet de bord qu'un succès partiel
        // (transforme silencieusement "non initialisé" en "0 explicite"), pour rien.
        if ($type === 'sortie' && $stockApres < 0 && ! $allowNegative) {
            throw ValidationException::withMessages([
                'stock' => "Stock insuffisant : {$quantite} demandés, {$stockAvant} disponibles.",
            ]);
        }

        if (! $varianteStock) {
            $varianteStock = VarianteStock::create([
                'organization_id' => $orgId,
                'produit_variante_id' => $varianteId,
                'site_id' => $siteId,
                'qte_stock' => 0,
            ]);
        }

        $varianteStock->update(['qte_stock' => $stockApres]);

        ProduitVariante::find($varianteId)?->produit?->resynchroniserQteStock();

        return MouvementStock::create([
            'organization_id' => $orgId,
            'site_id' => $siteId,
            'produit_variante_id' => $varianteId,
            'type' => $type,
            'quantite' => $quantite,
            'stock_avant' => $stockAvant,
            'stock_apres' => $stockApres,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    /**
     * Annule un mouvement précédemment appliqué via appliquer() : inverse le delta
     * sur VarianteStock (jamais en dessous de 0), resynchronise l'agrégat produit,
     * puis supprime la trace. Utilisé quand une opération source est invalidée
     * après coup (ex : réception de transfert renvoyée en TRANSIT).
     */
    private static function annulerMouvement(MouvementStock $mouvement): void
    {
        $varianteStock = VarianteStock::where('produit_variante_id', $mouvement->produit_variante_id)
            ->where('site_id', $mouvement->site_id)
            ->lockForUpdate()
            ->first();

        if ($varianteStock) {
            $delta = $mouvement->type === 'entree' ? -$mouvement->quantite : $mouvement->quantite;
            $varianteStock->update(['qte_stock' => max(0, $varianteStock->qte_stock + $delta)]);
            ProduitVariante::find($mouvement->produit_variante_id)?->produit?->resynchroniserQteStock();
        }

        $mouvement->delete();
    }

    /**
     * Enregistre la sortie de stock du site source : décrémente VarianteStock sur
     * site_source_id et trace le mouvement. Idempotent : ne rejoue pas si déjà
     * enregistré pour cette ligne (source_type/source_id/site_id/type).
     */
    public static function enregistrerSortieSource(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            $quantite = $ligne->quantite_chargee ?? $ligne->quantite_demandee;
            if ($quantite <= 0) {
                continue;
            }

            $dejaTraite = MouvementStock::where('source_type', TransfertLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $transfert->site_source_id)
                ->where('type', 'sortie')
                ->exists();

            if ($dejaTraite) {
                continue;
            }

            self::appliquer(
                varianteId: $ligne->variante_id,
                siteId: $transfert->site_source_id,
                orgId: $transfert->organization_id,
                type: 'sortie',
                quantite: $quantite,
                sourceType: TransfertLigne::class,
                sourceId: $ligne->id,
                userId: $userId,
            );
        }
    }

    /**
     * Enregistre l'entrée de stock au site destination : incrémente VarianteStock
     * sur site_destination_id et trace le mouvement. Utilise quantite_recue
     * (données réelles de réception). Idempotent.
     */
    public static function enregistrerEntreeDestination(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            // On comptabilise ce qui a réellement été reçu
            $quantite = $ligne->quantite_recue ?? $ligne->quantite_chargee ?? $ligne->quantite_demandee;
            if ($quantite <= 0) {
                continue;
            }

            $dejaTraite = MouvementStock::where('source_type', TransfertLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $transfert->site_destination_id)
                ->where('type', 'entree')
                ->exists();

            if ($dejaTraite) {
                continue;
            }

            self::appliquer(
                varianteId: $ligne->variante_id,
                siteId: $transfert->site_destination_id,
                orgId: $transfert->organization_id,
                type: 'entree',
                quantite: $quantite,
                sourceType: TransfertLigne::class,
                sourceId: $ligne->id,
                userId: $userId,
            );
        }
    }

    /**
     * Supprime les entrées de stock destination créées lors de la réception, en
     * inversant symétriquement leur effet sur VarianteStock (cf. annulerMouvement()).
     * Appelé quand un admin invalide une réception pour la renvoyer en transit.
     */
    public static function supprimerEntreeDestination(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');

        foreach ($transfert->lignes as $ligne) {
            $mouvements = MouvementStock::where('source_type', TransfertLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $transfert->site_destination_id)
                ->where('type', 'entree')
                ->get();

            foreach ($mouvements as $mouvement) {
                self::annulerMouvement($mouvement);
            }
        }
    }

    /**
     * Sortie de stock générique pour une variante sur un site donné — idempotent
     * sur (source_type, source_id, site_id, type). Utilisé par CommandeVenteService
     * (chargement logistique) et PdvCheckoutService (vente comptoir) — pas par les
     * transferts inter-sites, qui suivent un timing différent (voir
     * enregistrerSortieSource()/enregistrerEntreeDestination() ci-dessus).
     *
     * $allowNegative : cf. appliquer(). Réservé aux ventes (PDV/commande vente) — les
     * appelants décident au cas par cas selon Produit::autorise_vente_stock_negatif,
     * jamais un défaut implicite ici.
     *
     * @throws \Illuminate\Validation\ValidationException si la sortie dépasse le disponible et $allowNegative est faux
     */
    public static function sortirStock(
        string $varianteId,
        string $siteId,
        string $orgId,
        int $quantite,
        string $sourceType,
        string $sourceId,
        ?string $userId,
        bool $allowNegative = false,
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

        self::appliquer(
            varianteId: $varianteId,
            siteId: $siteId,
            orgId: $orgId,
            type: 'sortie',
            quantite: $quantite,
            sourceType: $sourceType,
            sourceId: $sourceId,
            userId: $userId,
            allowNegative: $allowNegative,
        );
    }

    /**
     * Quantité disponible pour une variante sur un site donné. Un site sans ligne
     * VarianteStock a strictement 0 de disponible — jamais de repli sur l'agrégat
     * legacy Produit::qte_stock, qui ne renseigne sur aucune agence en particulier.
     */
    public static function quantiteDisponible(string $varianteId, string $siteId): int
    {
        return (int) (VarianteStock::where('produit_variante_id', $varianteId)
            ->where('site_id', $siteId)
            ->value('qte_stock') ?? 0);
    }
}
