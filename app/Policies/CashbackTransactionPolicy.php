<?php

namespace App\Policies;

use App\Models\CashbackTransaction;
use App\Models\User;

class CashbackTransactionPolicy
{
    /**
     * Voir la liste : rôles ayant accès aux clients et aux finances.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_entreprise', 'manager', 'comptable']);
    }

    /**
     * Verser un cashback : rôles autorisés à valider des paiements.
     */
    public function update(User $user, CashbackTransaction $cashbackTransaction): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_entreprise', 'manager', 'comptable'])
            && $user->organization_id === $cashbackTransaction->organization_id;
    }
}
