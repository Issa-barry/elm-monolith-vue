<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleAccess;
use App\Support\Permissions\RoleVisibility;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class IndexRoleController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $roles = $this->visibleRoles()
            ->withCount(['users', 'permissions'])
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label ?? $role->name,
                'code' => $role->code,
                'is_system' => RoleAccess::isProtected($role),
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'updated_at' => $role->updated_at?->toISOString(),
            ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'totalPerms' => PermissionCatalog::totalCount(),
        ]);
    }

    /**
     * Rôles visibles pour l'utilisateur courant : les rôles système (partagés, organization_id
     * null) + les rôles métier de sa propre organisation — jamais ceux d'une autre organisation.
     */
    private function visibleRoles()
    {
        return RoleVisibility::query(auth()->user()->organization_id);
    }
}
