<?php

namespace App\Policies;

use App\Models\Packing;
use App\Models\User;

class PackingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('packings.read');
    }

    public function view(User $user, Packing $packing): bool
    {
        return $user->can('packings.read') && $packing->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('packings.create');
    }

    public function update(User $user, Packing $packing): bool
    {
        return $user->can('packings.update') && $packing->organization_id === $user->organization_id;
    }

    public function delete(User $user, Packing $packing): bool
    {
        return $user->can('packings.delete') && $packing->organization_id === $user->organization_id;
    }
}
