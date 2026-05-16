<?php

namespace App\Policies;

use App\Models\Contrat;
use App\Models\User;

class ContratPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rh-contrats.read');
    }

    public function view(User $user, Contrat $contrat): bool
    {
        return $user->can('rh-contrats.read')
            && $user->organization_id === $contrat->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('rh-contrats.create');
    }

    public function update(User $user, Contrat $contrat): bool
    {
        return $user->can('rh-contrats.update')
            && $user->organization_id === $contrat->organization_id;
    }

    public function delete(User $user, Contrat $contrat): bool
    {
        return $user->can('rh-contrats.delete')
            && $user->organization_id === $contrat->organization_id;
    }
}
