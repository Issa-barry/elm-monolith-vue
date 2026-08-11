<?php

namespace App\Policies;

use App\Models\Categorie;
use App\Models\User;

class CategoriePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('categories.read');
    }

    public function view(User $user, Categorie $categorie): bool
    {
        return $user->can('categories.read')
            && $user->organization_id === $categorie->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('categories.create');
    }

    public function update(User $user, Categorie $categorie): bool
    {
        return $user->can('categories.update')
            && $user->organization_id === $categorie->organization_id;
    }

    public function delete(User $user, Categorie $categorie): bool
    {
        return $user->can('categories.delete')
            && $user->organization_id === $categorie->organization_id;
    }
}
