<?php

namespace App\Services\Tresorerie\Obligations;

/**
 * Sentinel + accumulation partagés par tous les ObligationContributor, pour
 * que "site_id absent" (ex: fiche sans agence rattachée) soit regroupé de la
 * même façon quel que soit le contributeur.
 */
final class ObligationAccumulator
{
    public const SANS_AGENCE = '__sans_agence__';

    /** @param  array<string, array<string, float>>  $besoin */
    public static function ajouter(array &$besoin, ?string $siteId, string $colonne, float $restant, float $du): void
    {
        $cle = $siteId ?? self::SANS_AGENCE;
        $besoin[$cle][$colonne] = ($besoin[$cle][$colonne] ?? 0.0) + $restant;
        $besoin[$cle]["{$colonne}_du"] = ($besoin[$cle]["{$colonne}_du"] ?? 0.0) + $du;
    }
}
