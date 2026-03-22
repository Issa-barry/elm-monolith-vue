<?php

namespace App\Policies;

use App\Models\Proprietaire;
use App\Models\User;

class ProprietairePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('proprietaires.read');
    }

    public function view(User $user, Proprietaire $proprietaire): bool
    {
        return $user->can('proprietaires.read')
            && $this->sameOrganization($user, $proprietaire);
    }

    public function create(User $user): bool
    {
        return $user->can('proprietaires.create');
    }

    public function update(User $user, Proprietaire $proprietaire): bool
    {
        return $user->can('proprietaires.update')
            && $this->sameOrganization($user, $proprietaire);
    }

    public function delete(User $user, Proprietaire $proprietaire): bool
    {
        return $user->can('proprietaires.delete')
            && $this->sameOrganization($user, $proprietaire);
    }

    private function sameOrganization(User $user, Proprietaire $proprietaire): bool
    {
        return $user->organization_id === $proprietaire->organization_id;
    }
}
