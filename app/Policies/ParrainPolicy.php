<?php

namespace App\Policies;

use App\Models\Parrain;
use App\Models\User;

class ParrainPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('parrains.read');
    }

    public function view(User $user, Parrain $parrain): bool
    {
        return $user->can('parrains.read')
            && Parrain::visiblesPar($user)->whereKey($parrain->getKey())->exists();
    }

    /** Rattacher ou détacher un compte donne accès à ses commissions (ADR 0050). */
    public function gererCompte(User $user, Parrain $parrain): bool
    {
        return $user->can('rattachements-comptes.gerer')
            && Parrain::visiblesPar($user)->whereKey($parrain->getKey())->exists();
    }
}
