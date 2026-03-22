<?php

namespace App\Policies;

use App\Models\Produit;
use App\Models\User;

class ProduitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('produits.read');
    }

    public function view(User $user, Produit $produit): bool
    {
        return $user->can('produits.read')
            && $user->organization_id === $produit->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('produits.create');
    }

    public function update(User $user, Produit $produit): bool
    {
        return $user->can('produits.update')
            && $user->organization_id === $produit->organization_id;
    }

    public function delete(User $user, Produit $produit): bool
    {
        return $user->can('produits.delete')
            && $user->organization_id === $produit->organization_id;
    }
}
