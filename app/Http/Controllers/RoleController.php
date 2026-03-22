<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    private const RESOURCES = ['clients', 'prestataires', 'livreurs', 'proprietaires', 'produits', 'users'];
    private const ACTIONS   = ['create', 'read', 'update', 'delete'];

    public function index(): Response
    {
        abort_unless(auth()->user()->can('users.read'), 403);

        $roles = Role::withCount(['users', 'permissions'])->get()->map(fn (Role $role) => [
            'id'                => $role->id,
            'name'              => $role->name,
            'users_count'       => $role->users_count,
            'permissions_count' => $role->permissions_count,
            'updated_at'        => $role->updated_at?->toISOString(),
        ]);

        return Inertia::render('Roles/Index', [
            'roles'         => $roles,
            'totalPerms'    => count(self::RESOURCES) * count(self::ACTIONS),
        ]);
    }

    public function edit(Role $role): Response
    {
        abort_unless(auth()->user()->can('users.read'), 403);

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id'          => $role->id,
                'name'        => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
                'users_count' => $role->users()->count(),
            ],
            'resources' => self::RESOURCES,
            'actions'   => self::ACTIONS,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        if ($role->name === 'super_admin') {
            return back()->with('error', 'Le rôle super_admin ne peut pas être modifié.');
        }

        $permissions = $request->validate([
            'permissions'   => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ])['permissions'] ?? [];

        $role->syncPermissions($permissions);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return back()->with('success', 'Permissions mises à jour avec succès.');
    }
}
