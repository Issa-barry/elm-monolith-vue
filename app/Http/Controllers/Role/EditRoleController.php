<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleAccess;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class EditRoleController extends Controller
{
    public function __invoke(Role $role): Response
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        RoleAccess::authorizeSameOrganization($role);

        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $resources = $isSuperAdmin
            ? PermissionCatalog::RESOURCES
            : array_values(array_filter(PermissionCatalog::RESOURCES, fn ($r) => $r !== 'users'));

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label ?? $role->name,
                'code' => $role->code,
                'is_system' => RoleAccess::isProtected($role),
                'permissions' => $role->permissions->pluck('name')->values(),
                'users_count' => $role->users()->count(),
                'can_write' => RoleAccess::canManageRole($role),
            ],
            'resources' => $resources,
            'actions' => PermissionCatalog::ACTIONS,
            'standalone' => PermissionCatalog::STANDALONE,
            'domains' => PermissionCatalog::domainsFor($resources),
        ]);
    }
}
