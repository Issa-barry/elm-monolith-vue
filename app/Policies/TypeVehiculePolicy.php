<?php

namespace App\Policies;

use App\Models\TypeVehicule;
use App\Models\User;

class TypeVehiculePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('type-vehicules.read');
    }

    public function view(User $user, TypeVehicule $typeVehicule): bool
    {
        return $user->can('type-vehicules.read')
            && $this->sameOrganization($user, $typeVehicule);
    }

    public function create(User $user): bool
    {
        return $user->can('type-vehicules.create');
    }

    public function update(User $user, TypeVehicule $typeVehicule): bool
    {
        return $user->can('type-vehicules.update')
            && $this->sameOrganization($user, $typeVehicule);
    }

    public function delete(User $user, TypeVehicule $typeVehicule): bool
    {
        return $user->can('type-vehicules.delete')
            && $this->sameOrganization($user, $typeVehicule);
    }

    private function sameOrganization(User $user, TypeVehicule $typeVehicule): bool
    {
        return $user->organization_id === $typeVehicule->organization_id;
    }
}
