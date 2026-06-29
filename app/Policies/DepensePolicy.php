<?php

namespace App\Policies;

use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\User;
use App\Services\DroitCreationDepenseService;

class DepensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('depenses.read');
    }

    public function view(User $user, Depense $depense): bool
    {
        return $user->can('depenses.read')
            && $user->organization_id === $depense->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('depenses.create');
    }

    public function update(User $user, Depense $depense): bool
    {
        return $user->can('depenses.update')
            && $user->organization_id === $depense->organization_id
            && in_array($depense->statut, [StatutDepense::BROUILLON, StatutDepense::REJETE, StatutDepense::ANNULE]);
    }

    public function valider(User $user, Depense $depense): bool
    {
        if ($user->organization_id !== $depense->organization_id) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->can('depenses.update')) {
            return false;
        }

        $service = app(DroitCreationDepenseService::class);
        $droit = $service->droitValidationPour($user, $user->organization_id);

        return $service->peutValiderSurSite($user, $droit, $depense->site_id);
    }

    public function delete(User $user, Depense $depense): bool
    {
        return $user->can('depenses.delete')
            && $user->organization_id === $depense->organization_id
            && $depense->statut === StatutDepense::BROUILLON;
    }
}
