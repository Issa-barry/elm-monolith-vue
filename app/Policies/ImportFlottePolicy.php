<?php

namespace App\Policies;

use App\Models\ImportFlotte;
use App\Models\User;

class ImportFlottePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('imports-flotte.read');
    }

    public function view(User $user, ImportFlotte $importFlotte): bool
    {
        return $user->can('imports-flotte.read')
            && $user->organization_id === $importFlotte->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('imports-flotte.create');
    }
}
