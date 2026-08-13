<?php

namespace App\Services\Comptabilite;

use App\Enums\StatutExerciceComptable;
use App\Enums\StatutPeriodeComptable;
use App\Models\ExerciceComptable;
use App\Models\PeriodeComptable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Résout l'exercice/période comptable couvrant une date, en les créant à la
 * volée (exercice = année civile, période = mois civil) si absents — pour que
 * le moteur comptable fonctionne dès le premier événement sans exiger qu'un
 * administrateur ait pré-configuré le calendrier comptable. Un admin peut
 * ensuite ajuster/clôturer via l'écran dédié.
 */
class PeriodeComptableResolver
{
    public function resolveOrCreate(string $organizationId, Carbon $date): PeriodeComptable
    {
        $exercice = $this->resolveOrCreateExercice($organizationId, $date);

        $periode = PeriodeComptable::query()
            ->where('organization_id', $organizationId)
            ->whereDate('date_debut', '<=', $date->toDateString())
            ->whereDate('date_fin', '>=', $date->toDateString())
            ->first();

        if ($periode) {
            return $periode;
        }

        try {
            return PeriodeComptable::create([
                'organization_id' => $organizationId,
                'exercice_comptable_id' => $exercice->id,
                'date_debut' => $date->copy()->startOfMonth()->toDateString(),
                'date_fin' => $date->copy()->endOfMonth()->toDateString(),
                'statut' => StatutPeriodeComptable::OUVERTE->value,
            ]);
        } catch (QueryException $e) {
            // Course concurrente : une autre requête a créé la période entre-temps.
            return PeriodeComptable::query()
                ->where('organization_id', $organizationId)
                ->whereDate('date_debut', '<=', $date->toDateString())
                ->whereDate('date_fin', '>=', $date->toDateString())
                ->firstOrFail();
        }
    }

    private function resolveOrCreateExercice(string $organizationId, Carbon $date): ExerciceComptable
    {
        $exercice = ExerciceComptable::query()
            ->where('organization_id', $organizationId)
            ->whereDate('date_debut', '<=', $date->toDateString())
            ->whereDate('date_fin', '>=', $date->toDateString())
            ->first();

        if ($exercice) {
            return $exercice;
        }

        try {
            return ExerciceComptable::create([
                'organization_id' => $organizationId,
                'libelle' => 'Exercice '.$date->format('Y'),
                'date_debut' => $date->copy()->startOfYear()->toDateString(),
                'date_fin' => $date->copy()->endOfYear()->toDateString(),
                'statut' => StatutExerciceComptable::OUVERT->value,
            ]);
        } catch (QueryException $e) {
            return ExerciceComptable::query()
                ->where('organization_id', $organizationId)
                ->whereDate('date_debut', '<=', $date->toDateString())
                ->whereDate('date_fin', '>=', $date->toDateString())
                ->firstOrFail();
        }
    }
}
