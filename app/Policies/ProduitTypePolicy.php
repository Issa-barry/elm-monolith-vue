<?php

namespace App\Policies;

use App\Models\ProduitType;
use App\Models\User;

class ProduitTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('type-produits.read');
    }

    public function view(User $user, ProduitType $produitType): bool
    {
        return $user->can('type-produits.read')
            && $user->organization_id === $produitType->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('type-produits.create');
    }

    public function update(User $user, ProduitType $produitType): bool
    {
        return $user->can('type-produits.update')
            && $user->organization_id === $produitType->organization_id;
    }

    public function delete(User $user, ProduitType $produitType): bool
    {
        return $user->can('type-produits.delete')
            && $user->organization_id === $produitType->organization_id;
    }
}
