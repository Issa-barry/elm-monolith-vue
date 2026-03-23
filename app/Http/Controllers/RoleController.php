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
    private const RESOURCES = ['clients', 'prestataires', 'livreurs', 'proprietaires', 'vehicules', 'produits', 'packings', 'users'];
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

        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $resources = $isSuperAdmin
            ? self::RESOURCES
            : array_values(array_filter(self::RESOURCES, fn ($r) => $r !== 'users'));

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id'          => $role->id,
                'name'        => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
                'users_count' => $role->users()->count(),
            ],
            'resources' => $resources,
            'actions'   => self::ACTIONS,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $user = auth()->user();

        abort_unless($user->isSuperAdmin() || $user->hasRole('admin_entreprise'), 403);

        if ($role->name === 'super_admin') {
            return back()->with('error', 'Le rôle super_admin ne peut pas être modifié.');
        }

        $permissions = $request->validate([
            'permissions'   => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ])['permissions'] ?? [];

        // admin_entreprise ne peut pas toucher les permissions users.* — on les préserve telles quelles
        if (! $user->isSuperAdmin()) {
            $lockedPerms = $role->permissions()
                ->pluck('name')
                ->filter(fn ($p) => str_starts_with($p, 'users.'))
                ->values()
                ->toArray();

            $permissions = array_values(array_filter($permissions, fn ($p) => ! str_starts_with($p, 'users.')));
            $permissions = array_merge($permissions, $lockedPerms);
        }

        $role->syncPermissions($permissions);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return back()->with('success', 'Permissions mises à jour avec succès.');
    }
}
