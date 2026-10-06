<?php

namespace App\Support\Permissions;

use Spatie\Permission\Models\Role;

/**
 * Contrôles d'accès partagés par les actions du CRUD de rôles (`App\Http\Controllers\Role\*`,
 * extrait de l'ancien `RoleController`). Règle unique et centralisée : **seul `super_admin` est
 * un rôle système protégé** (jamais renommable, jamais supprimable, ses permissions restent
 * gérées par le bypass Gate::before — cf. AuthServiceProvider). Tout autre rôle — y compris
 * `admin_entreprise` et les rôles créés par RolesAndPermissionsSeeder — est un rôle métier
 * ordinaire, entièrement CRUDable : rien n'est jamais protégé simplement parce qu'il vient d'un
 * seeder.
 *
 * `name` (technique Spatie, cf. `Role\StoreRoleController`) n'est généré qu'une seule fois, à la
 * création, et n'est plus jamais réécrit ensuite (cf. `Role\UpdateRoleController`, qui ne touche
 * jamais `$role->name`) — même si le libellé change. Choix délibéré : `model_has_roles` référence
 * les rôles par id (renommer `name` ne casserait aucune affectation), mais plusieurs Policies
 * (CommandeVentePolicy, TransfertLogistiquePolicy, CashbackTransactionPolicy) et le middleware de
 * route `role:...` testent `admin_entreprise` en toutes lettres — renommer son `name` technique
 * romprait silencieusement ces vérifications. `label` (affiché) reste, lui, librement modifiable.
 */
final class RoleAccess
{
    /**
     * Seule définition de "rôle système protégé" de toute l'application — jamais redupliquée
     * ailleurs.
     */
    public static function isProtected(Role $role): bool
    {
        return $role->name === 'super_admin';
    }

    /**
     * Un rôle système (organization_id null) reste visible en LECTURE par tous les admins ; un
     * rôle métier n'appartient qu'à sa propre organisation — jamais accessible à une autre, même
     * en lecture, pour ne jamais laisser fuiter la matrice de permissions d'une organisation vers
     * une autre (cf. migration add_code_and_is_system_to_roles_table). La restriction d'ÉCRITURE
     * sur un rôle système est portée séparément par canManageRole() ci-dessous.
     */
    public static function authorizeSameOrganization(Role $role): void
    {
        abort_if(! RoleVisibility::belongsToOrganization($role, auth()->user()->organization_id), 403);
    }

    public static function canManageRoles(): bool
    {
        $user = auth()->user();

        return $user && ($user->isSuperAdmin() || $user->hasRole('admin_entreprise'));
    }

    /**
     * Un rôle système (organization_id null, partagé par TOUTES les organisations) ne peut plus
     * être modifié/supprimé que par un super_admin plateforme — décision produit actée le
     * 2026-09-06 : avant ce garde-fou, n'importe quel admin_entreprise pouvait changer les
     * permissions de `manager`/`commerciale`/`comptable`/`admin_entreprise`, affectant du même
     * coup toutes les AUTRES organisations qui utilisent ce même rôle partagé. Une organisation
     * qui veut un rôle sur mesure crée désormais SON PROPRE rôle via ce même CRUD (organization_id
     * renseigné) — canManageRoles() seul continue de s'appliquer à ces rôles-là.
     */
    public static function canManageRole(Role $role): bool
    {
        if (! self::canManageRoles()) {
            return false;
        }

        // Le rôle protégé (super_admin) garde son propre mécanisme, plus strict et à messages
        // conviviaux (isProtected() dans update()/destroy() — jamais un simple 403) : ne pas le
        // court-circuiter ici, sous peine de casser ces réponses pour un admin_entreprise qui
        // n'a de toute façon aucune prise sur ses permissions (gérées par Gate::before).
        if (self::isProtected($role)) {
            return true;
        }

        $user = auth()->user();

        return RoleVisibility::isWritableBy($role, $user->organization_id, $user->isSuperAdmin());
    }
}
