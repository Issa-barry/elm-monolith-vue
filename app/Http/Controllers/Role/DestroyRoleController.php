<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use App\Support\Permissions\RoleAccess;
use Illuminate\Http\RedirectResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DestroyRoleController extends Controller
{
    /**
     * `super_admin` n'est jamais supprimable. Tout autre rôle l'est, y compris `admin_entreprise`
     * — mais jamais aveuglément : un rôle encore attribué à des utilisateurs est refusé pour ne
     * jamais les laisser silencieusement sans rôle, l'appelant doit d'abord les réaffecter.
     */
    public function __invoke(Role $role): RedirectResponse
    {
        abort_unless(RoleAccess::canManageRole($role), 403);
        RoleAccess::authorizeSameOrganization($role);

        if (RoleAccess::isProtected($role)) {
            return back()->with('error', 'Ce rôle est un rôle système — il ne peut pas être supprimé.');
        }

        $usersCount = $role->users()->count();
        if ($usersCount > 0) {
            return back()->with('error', "Ce rôle est encore attribué à {$usersCount} utilisateur(s) — réaffectez-les avant de le supprimer.");
        }

        $role->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('success', 'Rôle supprimé.');
    }
}
