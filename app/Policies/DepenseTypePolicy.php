<?php

namespace App\Policies;

use App\Models\DepenseType;
use App\Models\User;

class DepenseTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('parametres.read');
    }

    public function create(User $user): bool
    {
        return $user->can('parametres.update');
    }

    public function update(User $user, DepenseType $type): bool
    {
        return $user->can('parametres.update')
            && $user->organization_id === $type->organization_id;
    }

    public function delete(User $user, DepenseType $type): bool
    {
        return $user->can('parametres.update')
            && $user->organization_id === $type->organization_id;
    }
}
