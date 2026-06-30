<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\Depense;

/**
 * Calcul unique du résumé (brut/frais/net/versé/reste) d'une commission vente
 * livreur, partagé entre l'index, le détail, les exports et le plafond de
 * paiement — pour éviter que ces écrans divergent entre eux.
 */
class CommissionVenteCalculatorService
{
    /**
     * Frais externes (Depense validées) par livreur, sur une période optionnelle.
     *
     * @param  array<int, string>  $livreurIds
     * @return array<string, float>
     */
    /**
     * @param  array<int, string>|null  $siteIds  Filtre Agence optionnel (page détail) — sans effet si vide.
     */
    public static function fraisDepensesParLivreur(string $orgId, array $livreurIds, ?string $periode = null, ?array $siteIds = null): array
    {
        if (empty($livreurIds)) {
            return [];
        }

        $query = Depense::where('beneficiaire_type', 'livreur')
            ->whereIn('beneficiaire_id', $livreurIds)
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('organization_id', $orgId);

        if ($periode !== null && $periode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($periode);
            $query->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59']);
        }

        if (! empty($siteIds)) {
            $query->whereIn('site_id', $siteIds);
        }

        return $query->get(['beneficiaire_id', 'montant'])
            ->groupBy('beneficiaire_id')
            ->map(fn ($d) => (float) $d->sum('montant'))
            ->toArray();
    }

    public static function fraisDepenseLivreur(string $orgId, string $livreurId, ?string $periode = null, ?array $siteIds = null): float
    {
        return self::fraisDepensesParLivreur($orgId, [$livreurId], $periode, $siteIds)[$livreurId] ?? 0.0;
    }

    /**
     * @return array{brut: float, frais_parts: float, frais_depenses: float, frais: float, net: float, verse: float, reste: float, statut: string}
     */
    public static function calculerResume(float $totalBrut, float $totalFraisParts, float $totalFraisDepenses, float $totalVerse): array
    {
        $fraisTotal = $totalFraisParts + $totalFraisDepenses;
        $net = max(0.0, $totalBrut - $fraisTotal);
        $reste = max(0.0, $net - $totalVerse);

        $statut = match (true) {
            $net > 0 && $totalVerse >= $net => StatutCommission::PAYE->value,
            $totalVerse > 0 => StatutCommission::PARTIEL->value,
            default => StatutCommission::IMPAYE->value,
        };

        return [
            'brut' => $totalBrut,
            'frais_parts' => $totalFraisParts,
            'frais_depenses' => $totalFraisDepenses,
            'frais' => $fraisTotal,
            'net' => $net,
            'verse' => $totalVerse,
            'reste' => $reste,
            'statut' => $statut,
        ];
    }
}
