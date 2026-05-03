<?php

namespace App\Policies;

use App\Models\Depense;
use App\Models\User;

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
            && $user->organization_id === $depense->organization_id;
    }

    public function delete(User $user, Depense $depense): bool
    {
        return $user->can('depenses.delete')
            && $user->organization_id === $depense->organization_id;
    }
}
