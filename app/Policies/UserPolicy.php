<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.read');
    }

    public function view(User $user, User $target): bool
    {
        return $user->can('users.read')
            && $user->organization_id === $target->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, User $target): bool
    {
        if ($target->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('users.update')
            && $user->organization_id === $target->organization_id;
    }

    public function delete(User $user, User $target): bool
    {
        if ($target->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        if (! $user->isSuperAdmin() || $user->id === $target->id) {
            return false;
        }

        // Super admin peut supprimer les comptes en attente (sans organisation)
        if (is_null($target->organization_id)) {
            return true;
        }

        return $user->organization_id === $target->organization_id;
    }
}
