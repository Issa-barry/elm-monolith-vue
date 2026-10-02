<?php

namespace App\Support\User;

/**
 * Garde-fou anti-élévation-de-privilège pour l'attribution de rôle depuis le CRUD utilisateurs —
 * extrait de l'ancien `UserController`.
 */
final class UserPrivilegeGuard
{
    /**
     * Élévation de privilège corrigée le 2026-08-21 pour `super_admin` : `role` n'était validé
     * que contre STAFF_ROLES (qui inclut `super_admin`), sans jamais vérifier que l'ACTEUR l'est
     * lui-même — n'importe quel utilisateur autorisé à modifier des comptes pouvait donc
     * s'attribuer ou attribuer `super_admin` à un tiers.
     *
     * Étendue le 2026-09-06 à `admin_entreprise` : `UserPolicy::update()` n'exige que la
     * permission `users.update` (+ même organisation) — un rôle personnalisé ne détenant que
     * cette permission (ex. un profil RH habilité à éditer des fiches) pouvait donc, via ce même
     * formulaire, s'auto-attribuer ou attribuer à un tiers `admin_entreprise`, sans jamais passer
     * par RoleAccess::canManageRoles() qui réserve pourtant la gestion des rôles à ce même
     * groupe. `isAdmin()` (super_admin OU admin_entreprise) est donc désormais requis pour
     * attribuer `admin_entreprise`, mirroring exact de canManageRoles().
     */
    public static function assertNoPrivilegeEscalation(string $role): void
    {
        if ($role === 'super_admin' && ! auth()->user()->isSuperAdmin()) {
            abort(403, 'Seul un super_admin peut attribuer ce rôle.');
        }

        if ($role === 'admin_entreprise' && ! auth()->user()->isAdmin()) {
            abort(403, 'Seul un administrateur peut attribuer ce rôle.');
        }
    }
}
