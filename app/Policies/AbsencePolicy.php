<?php

namespace App\Policies;

use App\Models\Absence;
use App\Models\User;

/**
 * Permissions de base des absences (docs/rh-absences.md). L'organisation et l'agence du salarié
 * sont revérifiées par App\Services\Rh\AbsenceService (PerimetreEmploye) : le Gate::before du
 * super administrateur court-circuite cette policy.
 */
class AbsencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rh-absences.read');
    }

    public function view(User $user, Absence $absence): bool
    {
        return $user->can('rh-absences.read')
            && $user->organization_id === $absence->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('rh-absences.create');
    }

    public function update(User $user, Absence $absence): bool
    {
        return $user->can('rh-absences.update')
            && $user->organization_id === $absence->organization_id;
    }

    /** Annuler : l'absence reste enregistrée avec le statut « Annulée », jamais supprimée. */
    public function annuler(User $user, Absence $absence): bool
    {
        return $user->can('rh-absences.annuler')
            && $user->organization_id === $absence->organization_id;
    }
}
