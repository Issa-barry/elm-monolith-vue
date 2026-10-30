<?php

namespace App\Services\Rh\Paie;

use App\Enums\StatutPeriodePaie;
use App\Models\PaiePeriode;
use App\Models\TransmissionPaie;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Mois figés de la paie d'une organisation (ADR 0039, ADR 0040) : un mois est figé dès qu'une
 * version de sa transmission RH a été validée, ou que la Comptabilité a validé sa paie. Aucune
 * affectation, aucun montant, aucune fin d'effet ne peut plus toucher un mois figé : un changement
 * prend effet au plus tôt le premier jour du mois qui suit le dernier mois figé.
 */
class CalendrierPaieService
{
    /** Premier jour qu'une modification RH peut encore toucher ; null si aucun mois n'est figé. */
    public function premierJourModifiable(string $organizationId): ?Carbon
    {
        $dernier = $this->dernierMoisFige($organizationId);

        return $dernier?->copy()->addMonthNoOverflow()->startOfMonth();
    }

    public function estFige(string $organizationId, int $annee, int $mois): bool
    {
        $premier = $this->premierJourModifiable($organizationId);

        return $premier !== null && Carbon::create($annee, $mois, 1)->lt($premier);
    }

    /**
     * @param  string  $champ  clé de l'erreur de validation
     */
    public function exigerModifiable(string $organizationId, CarbonInterface $jour, string $champ, string $action): void
    {
        $premier = $this->premierJourModifiable($organizationId);
        if ($premier !== null && $jour->copy()->startOfDay()->lt($premier)) {
            throw ValidationException::withMessages([
                $champ => "La paie est figée jusqu'au {$premier->copy()->subDay()->format('d/m/Y')} : {$action} au plus tôt le {$premier->format('d/m/Y')}.",
            ]);
        }
    }

    /** Premier jour du dernier mois figé. */
    private function dernierMoisFige(string $organizationId): ?Carbon
    {
        $candidats = [];

        $transmission = TransmissionPaie::where('organization_id', $organizationId)
            ->whereIn('statut', TransmissionPaie::STATUTS_FIGES)
            ->orderByDesc('annee')->orderByDesc('mois')
            ->first(['annee', 'mois']);
        if ($transmission !== null) {
            $candidats[] = Carbon::create($transmission->annee, $transmission->mois, 1);
        }

        $periode = PaiePeriode::where('organization_id', $organizationId)
            ->whereIn('statut', array_map(
                fn (StatutPeriodePaie $s) => $s->value,
                array_filter(StatutPeriodePaie::cases(), fn (StatutPeriodePaie $s) => $s->estVerrouille()),
            ))
            ->orderByDesc('annee')->orderByDesc('mois')
            ->first(['annee', 'mois']);
        if ($periode !== null) {
            $candidats[] = Carbon::create($periode->annee, $periode->mois, 1);
        }

        if ($candidats === []) {
            return null;
        }

        return collect($candidats)->sortDesc()->first()->startOfDay();
    }
}
