<?php

namespace App\Policies;

use App\Models\Fournisseur;
use App\Models\User;

class FournisseurPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('fournisseurs.read');
    }

    public function view(User $user, Fournisseur $fournisseur): bool
    {
        return $user->can('fournisseurs.read')
            && $this->sameOrganization($user, $fournisseur);
    }

    public function create(User $user): bool
    {
        return $user->can('fournisseurs.create');
    }

    public function update(User $user, Fournisseur $fournisseur): bool
    {
        return $user->can('fournisseurs.update')
            && $this->sameOrganization($user, $fournisseur);
    }

    public function delete(User $user, Fournisseur $fournisseur): bool
    {
        return $user->can('fournisseurs.delete')
            && $this->sameOrganization($user, $fournisseur);
    }

    private function sameOrganization(User $user, Fournisseur $fournisseur): bool
    {
        return $user->organization_id === $fournisseur->organization_id;
    }
}
