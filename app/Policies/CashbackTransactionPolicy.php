<?php

namespace App\Policies;

use App\Models\CashbackTransaction;
use App\Models\User;

class CashbackTransactionPolicy
{
    /**
     * Voir la liste : gouverné par la permission dédiée `cashback.read` de la matrice de rôles,
     * pas par une liste de noms de rôles figée — celle-ci ignorait tout rôle personnalisé ou
     * système auquel `cashback.read` a été accordé (ex: Commerciale), causant un 403 malgré la
     * case cochée dans /backoffice/roles (régression Sentry du 07/09/2026). super_admin,
     * admin_entreprise, manager et comptable ont tous déjà `cashback.read` dans
     * RolesAndPermissionsSeeder — aucune régression pour eux.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('cashback.read');
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
