<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Calcul des périodes comptables pour les commissions livreurs.
 *
 * Livreur : 2 périodes par mois
 *   P1 : du 1er au 15 inclus
 *   P2 : du 16 au dernier jour du mois
 *
 * Propriétaire : 1 période par mois (mensuel)
 *   M  : du 1er au dernier jour du mois
 *
 * Format de code : "YYYY-MM-P1" | "YYYY-MM-P2" | "YYYY-MM-M"
 * Exemple        : "2026-04-P1"
 */
class PeriodeComptableService
{
    public const PART_P1 = 'P1';

    public const PART_P2 = 'P2';

    public const PART_M = 'M';

    // ── Calcul du code ────────────────────────────────────────────────────────

    /**
     * Retourne le code de période livreur pour une date donnée.
     *
     * @example codeForLivreur(2026-04-12) → "2026-04-P1"
     * @example codeForLivreur(2026-04-26) → "2026-04-P2"
     */
    public static function codeForLivreur(Carbon $date): string
    {
        $half = $date->day <= 15 ? self::PART_P1 : self::PART_P2;

        return $date->format('Y-m').'-'.$half;
    }

    /**
     * Retourne le code de période propriétaire pour une date donnée (mensuel).
     *
     * @example codeForProprietaire(2026-04-12) → "2026-04-M"
     */
    public static function codeForProprietaire(Carbon $date): string
    {
        return $date->format('Y-m').'-'.self::PART_M;
    }

    /**
     * Retourne le code de période selon le type de bénéficiaire.
     */
    public static function codeFor(string $typeBeneficiaire, Carbon $date): string
    {
        return $typeBeneficiaire === 'livreur'
            ? self::codeForLivreur($date)
            : self::codeForProprietaire($date);
    }

    // ── Période courante ──────────────────────────────────────────────────────

    /**
     * Code de la période courante pour les livreurs.
     */
    public static function periodeCouranteLivreur(): string
    {
        return self::codeForLivreur(now());
    }

    /**
     * Code de la période courante pour les propriétaires.
     */
    public static function periodeCouranteProprietaire(): string
    {
        return self::codeForProprietaire(now());
    }

    // ── Plage de dates ────────────────────────────────────────────────────────

    /**
     * Retourne [Carbon $debut, Carbon $fin] pour un code de période.
     *
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws InvalidArgumentException si le code est invalide
     */
    public static function dateRangeForCode(string $code): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(P1|P2|M)$/', $code, $m)) {
            throw new InvalidArgumentException("Code de période invalide : {$code}");
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $part = $m[3];

        return match ($part) {
            self::PART_P1 => [
                Carbon::create($year, $month, 1)->startOfDay(),
                Carbon::create($year, $month, 15)->endOfDay(),
            ],
            self::PART_P2 => [
                Carbon::create($year, $month, 16)->startOfDay(),
                Carbon::create($year, $month)->endOfMonth()->endOfDay(),
            ],
            self::PART_M => [
                Carbon::create($year, $month, 1)->startOfDay(),
                Carbon::create($year, $month)->endOfMonth()->endOfDay(),
            ],
        };
    }

    // ── Label lisible ─────────────────────────────────────────────────────────

    /**
     * Retourne un libellé lisible pour un code de période.
     *
     * @example labelForCode("2026-04-P1") → "P1 du 01 au 15 Avril 2026"
     * @example labelForCode("2026-04-P2") → "P2 du 16 au 30 Avril 2026"
     * @example labelForCode("2026-04-M")  → "Avril 2026"
     */
    public static function labelForCode(string $code): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(P1|P2|M)$/', $code, $m)) {
            return $code;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $part = $m[3];

        $monthName = ucfirst(
            Carbon::create($year, $month, 1)->locale('fr')->translatedFormat('F')
        );

        $lastDay = str_pad((string) Carbon::create($year, $month)->daysInMonth, 2, '0', STR_PAD_LEFT);

        return match ($part) {
            self::PART_P1 => "P1 du 01 au 15 {$monthName} {$year}",
            self::PART_P2 => "P2 du 16 au {$lastDay} {$monthName} {$year}",
            self::PART_M  => "{$monthName} {$year}",
        };
    }

    // ── Enumération des périodes ──────────────────────────────────────────────

    /**
     * Retourne la liste ordonnée (du plus récent au plus ancien) des codes de
     * périodes livreurs entre deux dates.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function periodesLivreurBetween(Carbon $from, Carbon $to): array
    {
        $periodes = [];

        // On part du début du mois de $from et on avance jusqu'à $to
        $cursor = $from->copy()->startOfMonth();
        $limit = $to->copy()->endOfMonth();

        while ($cursor <= $limit) {
            // P1 de ce mois
            $codeP1 = self::codeForLivreur(Carbon::create($cursor->year, $cursor->month, 1));
            [, $endP1] = self::dateRangeForCode($codeP1);
            if ($endP1 >= $from) {
                $periodes[$codeP1] = [
                    'code' => $codeP1,
                    'label' => self::labelForCode($codeP1),
                ];
            }

            // P2 de ce mois
            $codeP2 = self::codeForLivreur(Carbon::create($cursor->year, $cursor->month, 16));
            [$startP2] = self::dateRangeForCode($codeP2);
            if ($startP2 <= $to) {
                $periodes[$codeP2] = [
                    'code' => $codeP2,
                    'label' => self::labelForCode($codeP2),
                ];
            }

            $cursor->addMonth();
        }

        // Tri décroissant (plus récent en premier)
        arsort($periodes);

        return array_values($periodes);
    }

    /**
     * Liste des périodes livreur depuis une date jusqu'à aujourd'hui.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function periodesDisponibles(Carbon $from): array
    {
        return self::periodesLivreurBetween($from, now());
    }
}
