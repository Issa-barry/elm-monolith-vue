<?php

namespace App\Policies;

use App\Models\Livreur;
use App\Models\User;

class LivreurPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('livreurs.read');
    }

    public function view(User $user, Livreur $livreur): bool
    {
        return $user->can('livreurs.read')
            && $this->sameOrganization($user, $livreur);
    }

    public function create(User $user): bool
    {
        return $user->can('livreurs.create');
    }

    public function update(User $user, Livreur $livreur): bool
    {
        return $user->can('livreurs.update')
            && $this->sameOrganization($user, $livreur);
    }

    public function delete(User $user, Livreur $livreur): bool
    {
        return $user->can('livreurs.delete')
            && $this->sameOrganization($user, $livreur);
    }

    private function sameOrganization(User $user, Livreur $livreur): bool
    {
        return $user->organization_id === $livreur->organization_id;
    }
}
