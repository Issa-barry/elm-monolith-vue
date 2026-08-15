<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'code' => $role->code,
            'is_system' => $role->is_system,
            'users_count' => $role->users_count,
            'permissions_count' => $role->permissions_count,
            'updated_at' => $role->updated_at?->toISOString(),
        ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'totalPerms' => count(self::RESOURCES) * count(self::ACTIONS),
        ]);
    }

    public function create(): Response
    {
        abort_unless($this->canManageRoles(), 403);

        return Inertia::render('Roles/Create');
    }

    /**
     * Un rôle créé ici démarre toujours sans aucune permission (jamais une copie d'un rôle
     * existant) — principe du moindre privilège : l'admin les accorde ensuite explicitement
     * depuis l'écran d'édition (même matrice que pour les rôles système), plutôt que de risquer
     * d'hériter silencieusement de droits non voulus.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManageRoles(), 403);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name'),
            ],
            'code' => ['nullable', 'string', 'max:10'],
        ], [
            'name.regex' => 'Le nom technique doit être en minuscules, sans espace ni accent (ex: chef_agence).',
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'is_system' => false,
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return redirect()->route('roles.edit', $role)->with('success', 'Rôle créé — définissez ses permissions ci-dessous.');
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
                'code' => $role->code,
                'is_system' => $role->is_system,
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

        abort_unless($this->canManageRoles(), 403);

        // Le trinôme (et, pour un rôle non-système, le nom) sont indépendants de la matrice de
        // permissions — traités avant, jamais bloqués par la protection super_admin ci-dessous
        // (qui ne porte que sur les permissions).
        $identite = $request->validate([
            'code' => ['nullable', 'string', 'max:10'],
            'name' => [
                $role->is_system ? 'prohibited' : 'sometimes',
                'string', 'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->ignore($role->id),
            ],
        ], [
            'name.prohibited' => 'Le nom d\'un rôle système ne peut pas être modifié.',
            'name.regex' => 'Le nom technique doit être en minuscules, sans espace ni accent (ex: chef_agence).',
        ]);
        $role->code = $identite['code'] ?? null;
        if (array_key_exists('name', $identite)) {
            $role->name = $identite['name'];
        }
        $role->save();

        if ($role->name === 'super_admin') {
            return back()->with('success', 'Rôle mis à jour — ses permissions restent gérées automatiquement.');
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

        return back()->with('success', 'Rôle mis à jour avec succès.');
    }

    /**
     * Un rôle système (créé par RolesAndPermissionsSeeder) n'est jamais supprimable — son name
     * est référencé en dur ailleurs (middleware `role:` des routes, quelques Policies), le
     * supprimer casserait ces endroits pour tous les clients, pas seulement celui qui l'a fait.
     * Un rôle custom encore rattaché à des utilisateurs est refusé pour ne jamais les laisser
     * silencieusement sans rôle — l'appelant doit d'abord les réaffecter.
     */
    public function destroy(Role $role): RedirectResponse
    {
        abort_unless($this->canManageRoles(), 403);

        if ($role->is_system) {
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

    private function canManageRoles(): bool
    {
        $user = auth()->user();

        return $user && ($user->isSuperAdmin() || $user->hasRole('admin_entreprise'));
    }
}
