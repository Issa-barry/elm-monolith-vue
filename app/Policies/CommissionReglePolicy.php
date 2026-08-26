<?php

namespace App\Policies;

use App\Models\CommissionRegle;
use App\Models\User;

class CommissionReglePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('parametres.read');
    }

    public function create(User $user): bool
    {
        return $user->can('parametres.update');
    }

    public function update(User $user, CommissionRegle $regle): bool
    {
        return $user->can('parametres.update')
            && $user->organization_id === $regle->organization_id;
    }
}
