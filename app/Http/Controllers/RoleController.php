<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    private const RESOURCES = [
        // Personnes
        'clients', 'prestataires', 'livreurs', 'proprietaires',
        // Véhicules & terrain
        'vehicules', 'equipes-livraison', 'sites',
        // Commerce
        'produits', 'packings', 'ventes', 'achats', 'factures', 'commissions', 'cashback', 'pdv',
        // Opérations
        'logistique', 'transferts', 'receptions',
        // Finances
        'depenses', 'comptabilite', 'journal-financier',
        // RH
        'rh-employes', 'rh-contrats', 'rh-paie',
        // Administration
        'users',
        // Paramètres
        'parametres', 'parametres-produits', 'parametres-depenses', 'parametres-ventes', 'parametres-systeme', 'modules-metier',
    ];

    private const ACTIONS = ['create', 'read', 'update', 'delete'];

    public function index(): Response
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $roles = Role::withCount(['users', 'permissions'])->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'users_count' => $role->users_count,
            'permissions_count' => $role->permissions_count,
            'updated_at' => $role->updated_at?->toISOString(),
        ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'totalPerms' => count(self::RESOURCES) * count(self::ACTIONS),
        ]);
    }

    public function edit(Role $role): Response
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $resources = $isSuperAdmin
            ? self::RESOURCES
            : array_values(array_filter(self::RESOURCES, fn ($r) => $r !== 'users'));

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
                'users_count' => $role->users()->count(),
            ],
            'resources' => $resources,
            'actions' => self::ACTIONS,
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
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ])['permissions'] ?? [];

        // Permissions hors matrice CRUD (workflow, standalone) → préservées telles quelles,
        // car l'UI ne les affiche pas et syncPermissions les effacerait sinon.
        $crudKeys = collect(self::RESOURCES)
            ->flatMap(fn ($r) => collect(self::ACTIONS)->map(fn ($a) => "{$r}.{$a}"))
            ->all();

        $nonCrudFromRole = $role->permissions()
            ->pluck('name')
            ->reject(fn ($p) => in_array($p, $crudKeys, true))
            ->values()
            ->toArray();

        // admin_entreprise ne peut pas toucher les permissions users.* (cachées de l'UI)
        if (! $user->isSuperAdmin()) {
            $usersFromRole = $role->permissions()
                ->pluck('name')
                ->filter(fn ($p) => str_starts_with($p, 'users.'))
                ->values()
                ->toArray();

            $permissions = array_values(array_filter($permissions, fn ($p) => ! str_starts_with($p, 'users.')));
            $permissions = array_merge($permissions, $usersFromRole);
        }

        $role->syncPermissions(array_merge($permissions, $nonCrudFromRole));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return back()->with('success', 'Permissions mises à jour avec succès.');
    }
}
