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
        return $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('users.update')
            && $user->organization_id === $target->organization_id;
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can('users.delete')
            && $user->id !== $target->id;
    }
}
