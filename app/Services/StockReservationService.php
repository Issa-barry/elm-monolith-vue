<?php

namespace App\Services;

use App\Enums\StatutReservationStock;
use App\Models\StockReservation;
use App\Models\VarianteStock;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des réservations de stock — la quantité affectée à une commande vente confirmée
 * (A_CHARGER) mais pas encore physiquement sortie. variante_stocks.qte_reservee est un compteur
 * dénormalisé dérivé des lignes StockReservation ACTIVE ; ces lignes restent la preuve métier
 * (jamais supprimées, seulement LIBEREE/CONSOMMEE — même principe d'immuabilité que
 * MouvementStock). Introduit le 24/08/2026 : avant cela, `Disponible` reflétait le seul stock
 * physique — deux commandes concurrentes pouvaient toutes deux être confirmées en promettant le
 * même stock, le conflit n'étant détecté qu'au chargement de l'une des deux (cf.
 * MouvementStockService::quantiteDisponible(), qui nette désormais qte_reservee).
 */
class StockReservationService
{
    /**
     * Réserve une quantité pour une variante × site, au nom d'une source (ligne de commande
     * vente confirmée). Verrouille (lockForUpdate) la ligne VarianteStock concernée — l'appelant
     * s'exécute déjà dans une DB::transaction, même convention que MouvementStockService::
     * appliquer() — puis incrémente qte_reservee et trace une StockReservation ACTIVE.
     * Idempotent par (source_type, source_id, site_id) : un second appel pour la même source est
     * un no-op silencieux (le workflow ne repasse de toute façon jamais par confirmer() pour une
     * commande déjà A_CHARGER).
     *
     * $allowNegative suit la même politique globale que MouvementStockService::appliquer()
     * (Parametre::isVentesAutoriseesSansStock()) — jamais un défaut implicite, l'appelant décide.
     *
     * @throws ValidationException si la quantité dépasse le disponible (stock physique moins
     *                             déjà réservé par d'autres sources) et $allowNegative est faux
     */
    public static function reserver(
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

        $dejaReserve = StockReservation::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('site_id', $siteId)
            ->exists();

        if ($dejaReserve) {
            return;
        }

        $varianteStock = VarianteStock::where('produit_variante_id', $varianteId)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->first();

        $qteStock = $varianteStock?->qte_stock ?? 0;
        $qteReserveeExistante = $varianteStock?->qte_reservee ?? 0;
        $disponible = $qteStock - $qteReserveeExistante;

        if ($quantite > $disponible && ! $allowNegative) {
            throw ValidationException::withMessages([
                'stock' => "Stock insuffisant pour réserver : {$quantite} demandés, {$disponible} disponibles.",
            ]);
        }

        if (! $varianteStock) {
            $varianteStock = VarianteStock::create([
                'organization_id' => $orgId,
                'produit_variante_id' => $varianteId,
                'site_id' => $siteId,
                'qte_stock' => 0,
                'qte_reservee' => 0,
            ]);
        }

        $varianteStock->update(['qte_reservee' => $qteReserveeExistante + $quantite]);

        StockReservation::create([
            'organization_id' => $orgId,
            'site_id' => $siteId,
            'produit_variante_id' => $varianteId,
            'quantite' => $quantite,
            'statut' => StatutReservationStock::ACTIVE,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $userId,
            'reserved_at' => now(),
        ]);
    }

    /**
     * Libère une réservation active sans jamais décrémenter le stock physique — utilisé quand
     * une commande est annulée avant chargement. Décrémente qte_reservee (jamais sous 0) et
     * marque la réservation LIBEREE. Idempotent : no-op si aucune réservation active pour cette
     * source (jamais réservée, ou déjà libérée/consommée).
     */
    public static function liberer(string $sourceType, string $sourceId, string $siteId): void
    {
        self::terminer($sourceType, $sourceId, $siteId, StatutReservationStock::LIBEREE);
    }

    /**
     * Consomme une réservation active — utilisé au chargement validé, AVANT le décrément
     * physique correspondant (MouvementStockService::sortirStock()) : sinon le garde-fou "le
     * stock physique ne descend jamais sous le réservé" de MouvementStockService::appliquer()
     * rejetterait à tort la propre consommation de cette réservation. La quantité réellement
     * chargée peut différer de la quantité réservée (écart) — la réservation est intégralement
     * libérée dans tous les cas, jamais partiellement : l'écart non chargé redevient
     * automatiquement disponible pour d'autres commandes. Idempotent.
     */
    public static function consommer(string $sourceType, string $sourceId, string $siteId): void
    {
        self::terminer($sourceType, $sourceId, $siteId, StatutReservationStock::CONSOMMEE);
    }

    private static function terminer(string $sourceType, string $sourceId, string $siteId, StatutReservationStock $statutFinal): void
    {
        $reservation = StockReservation::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('site_id', $siteId)
            ->where('statut', StatutReservationStock::ACTIVE)
            ->first();

        if (! $reservation) {
            return;
        }

        $varianteStock = VarianteStock::where('produit_variante_id', $reservation->produit_variante_id)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->first();

        if ($varianteStock) {
            $varianteStock->update(['qte_reservee' => max(0, $varianteStock->qte_reservee - $reservation->quantite)]);
        }

        $reservation->update(['statut' => $statutFinal, 'released_at' => now()]);
    }

    /**
     * Quantité activement réservée par UNE source précise, sur ce site — permet de "rendre" sa
     * propre réservation au calcul de disponible au moment du recontrôle (une commande ne doit
     * jamais être bloquée par SA PROPRE réservation), cf. CommandeVenteService::
     * verifierDisponibiliteLignes().
     */
    public static function quantiteReserveeActivePourSource(string $sourceType, string $sourceId, string $siteId): int
    {
        return (int) StockReservation::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('site_id', $siteId)
            ->where('statut', StatutReservationStock::ACTIVE)
            ->sum('quantite');
    }
}
