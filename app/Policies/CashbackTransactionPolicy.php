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
     * Valider un cashback (étape 1) : super_admin et admin_entreprise uniquement.
     */
    public function valider(User $user, CashbackTransaction $cashbackTransaction): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_entreprise'])
            && $user->organization_id === $cashbackTransaction->organization_id;
    }

    /**
     * Verser un cashback (étape 2) : seulement sur une transaction déjà validée.
     */
    public function update(User $user, CashbackTransaction $cashbackTransaction): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_entreprise', 'manager', 'comptable'])
            && $user->organization_id === $cashbackTransaction->organization_id;
    }
}
