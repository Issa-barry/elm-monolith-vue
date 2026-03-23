<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicule;

class VehiculePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('vehicules.read');
    }

    public function view(User $user, Vehicule $vehicule): bool
    {
        return $user->can('vehicules.read')
            && $this->sameOrganization($user, $vehicule);
    }

    public function create(User $user): bool
    {
        return $user->can('vehicules.create');
    }

    public function update(User $user, Vehicule $vehicule): bool
    {
        return $user->can('vehicules.update')
            && $this->sameOrganization($user, $vehicule);
    }

    public function delete(User $user, Vehicule $vehicule): bool
    {
        return $user->can('vehicules.delete')
            && $this->sameOrganization($user, $vehicule);
    }

    private function sameOrganization(User $user, Vehicule $vehicule): bool
    {
        return $user->organization_id === $vehicule->organization_id;
    }
}
