<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\CommissionEnveloppePart;
use App\Models\Depense;
use App\Support\Commission\CommissionPeriodeFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcul unique du résumé (brut/frais/net/versé/reste) d'une commission vente
 * livreur, partagé entre l'index, le détail, les exports et le plafond de
 * paiement — pour éviter que ces écrans divergent entre eux.
 */
class CommissionVenteCalculatorService
{
    /**
     * Frais externes (Depense validées) par livreur, sur une ou plusieurs périodes optionnelles
     * (union, cf. CommissionPeriodeFilter).
     *
     * @param  array<int, string>  $livreurIds
     * @return array<string, float>
     */
    /**
     * @param  array<int, string>|null  $siteIds  Filtre Agence optionnel (page détail) — sans effet si vide.
     */
    public static function fraisDepensesParLivreur(string $orgId, array $livreurIds, array|string|null $periodes = null, ?array $siteIds = null): array
    {
        if (empty($livreurIds)) {
            return [];
        }

        $query = Depense::where('beneficiaire_type', 'livreur')
            ->whereIn('beneficiaire_id', $livreurIds)
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('organization_id', $orgId);

        CommissionPeriodeFilter::appliquer($query, CommissionPeriodeFilter::normaliserCodes($periodes), 'date_depense', colonneDate: true);

        if (! empty($siteIds)) {
            $query->whereIn('site_id', $siteIds);
        }

        return $query->get(['beneficiaire_id', 'montant'])
            ->groupBy('beneficiaire_id')
            ->map(fn ($d) => (float) $d->sum('montant'))
            ->toArray();
    }

    /**
     * Frais externes (Depense validées) par livreur, limités aux périodes de paie (quinzaines) où
     * le livreur a au moins une des commissions fournies — même rattachement par date que la
     * fiche de paie (PeriodeCalculatorService). Utilisé quand la liste est filtrée par statut :
     * une dépense d'une période dont aucune commission n'est retenue n'est jamais déduite.
     *
     * @param  Collection<string, Collection<int, CommissionEnveloppePart>>  $partsParLivreur
     * @param  array<int, string>|null  $siteIds  Filtre Agence optionnel — sans effet si vide.
     * @return array<string, float>
     */
    public static function fraisDepensesSurPeriodesDesParts(string $orgId, Collection $partsParLivreur, ?array $siteIds = null): array
    {
        $codesParLivreur = $partsParLivreur
            ->mapWithKeys(fn (Collection $parts, $livreurId) => [(string) $livreurId => $parts
                ->pluck('enveloppe.earned_at')
                ->filter()
                ->map(fn ($date) => PeriodeComptableService::codeForLivreur(Carbon::parse($date)))
                ->unique()
                ->values()
                ->all()])
            ->filter();

        if ($codesParLivreur->isEmpty()) {
            return [];
        }

        $query = Depense::where('beneficiaire_type', 'livreur')
            ->whereIn('beneficiaire_id', $codesParLivreur->keys()->all())
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('organization_id', $orgId);

        CommissionPeriodeFilter::appliquer($query, $codesParLivreur->flatten()->unique()->values()->all(), 'date_depense', colonneDate: true);

        if (! empty($siteIds)) {
            $query->whereIn('site_id', $siteIds);
        }

        return $query->get(['beneficiaire_id', 'date_depense', 'montant'])
            ->filter(fn (Depense $d) => $d->date_depense !== null && in_array(
                PeriodeComptableService::codeForLivreur(Carbon::parse($d->date_depense)),
                $codesParLivreur->get((string) $d->beneficiaire_id, []),
                true,
            ))
            ->groupBy(fn (Depense $d) => (string) $d->beneficiaire_id)
            ->map(fn (Collection $d) => (float) $d->sum('montant'))
            ->all();
    }

    public static function fraisDepenseLivreur(string $orgId, string $livreurId, ?string $periode = null, ?array $siteIds = null): float
    {
        return self::fraisDepensesParLivreur($orgId, [$livreurId], $periode, $siteIds)[$livreurId] ?? 0.0;
    }

    /**
     * @param  float  $totalAPayer  somme des montants réellement dus par part (montant_actuel s'il a été
     *                              ajusté, sinon montant_net) — voir CommissionEnveloppePart::getMontantAPayerAttribute().
     *                              C'est cette valeur, et non le brut théorique, qui détermine le net à payer :
     *                              un ajustement validé doit se refléter immédiatement dans les listes/exports.
     * @return array{brut: float, frais_parts: float, frais_depenses: float, frais: float, net: float, verse: float, reste: float, statut: string}
     */
    public static function calculerResume(float $totalBrut, float $totalFraisParts, float $totalAPayer, float $totalFraisDepenses, float $totalVerse): array
    {
        $fraisTotal = $totalFraisParts + $totalFraisDepenses;
        $net = max(0.0, $totalAPayer - $totalFraisDepenses);
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
