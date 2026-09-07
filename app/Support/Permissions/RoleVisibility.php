<?php

namespace App\Support\Permissions;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * Filtre unique "quels rôles une organisation peut voir/affecter" : les rôles système
 * (`organization_id` null, partagés par toutes les organisations) + les rôles métier de
 * cette organisation — jamais ceux d'une autre organisation. Utilisé par RoleController,
 * UserController (affectation) et les contrôleurs de paramétrage (Ventes/Dépenses) — avant
 * cette classe, ce filtre était soit dupliqué, soit absent (`Role::orderBy('name')->get()`
 * sans scope), ce qui exposait/modifiait les rôles d'autres organisations.
 */
final class RoleVisibility
{
    public static function query(?string $organizationId): Builder
    {
        return Role::query()->where(
            fn (Builder $q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)
        );
    }

    public static function belongsToOrganization(Role $role, ?string $organizationId): bool
    {
        return $role->organization_id === null || $role->organization_id === $organizationId;
    }

    /**
     * Un rôle système (organization_id null, partagé par toutes les organisations) n'est
     * modifiable que par un super_admin plateforme — décision produit du 2026-09-06 (cf.
     * RoleController::canManageRole()). Un rôle propre à l'organisation (organization_id ===
     * $organizationId) reste entièrement sous son contrôle. Règle unique, réutilisée par
     * RoleController, VenteParametrageController et DepenseParametrageController — jamais
     * redupliquée : muter un rôle système depuis un écran de paramétrage d'organisation
     * affecterait sinon silencieusement toutes les AUTRES organisations qui l'utilisent.
     */
    public static function isWritableBy(Role $role, ?string $organizationId, bool $actorIsSuperAdmin): bool
    {
        if ($role->organization_id === null) {
            return $actorIsSuperAdmin;
        }

        return $role->organization_id === $organizationId;
    }
}
