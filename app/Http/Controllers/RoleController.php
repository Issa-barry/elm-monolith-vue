<?php

namespace App\Http\Controllers;

use App\Services\RoleNamingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRUD de rôles en self-service, par organisation. Règle unique et centralisée pour toute la
 * classe : **seul `super_admin` est un rôle système protégé** (jamais renommable, jamais
 * supprimable, ses permissions restent gérées par le bypass Gate::before —
 * cf. AuthServiceProvider). Tout autre rôle — y compris `admin_entreprise` et les rôles créés par
 * RolesAndPermissionsSeeder — est un rôle métier ordinaire, entièrement CRUDable : rien n'est
 * jamais protégé simplement parce qu'il vient d'un seeder.
 *
 * `name` (technique Spatie) n'est généré qu'une seule fois, à la création, et n'est plus jamais
 * réécrit ensuite — même si le libellé change. Choix délibéré : `model_has_roles` référence les
 * rôles par id (renommer `name` ne casserait aucune affectation), mais plusieurs Policies
 * (CommandeVentePolicy, TransfertLogistiquePolicy, CashbackTransactionPolicy) et le middleware de
 * route `role:...` testent `admin_entreprise` en toutes lettres — renommer son `name` technique
 * romprait silencieusement ces vérifications. `label` (affiché) reste, lui, librement modifiable.
 */
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

    public function __construct(private readonly RoleNamingService $naming) {}

    public function index(): Response
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
                'is_system' => $this->isProtected($role),
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
     * depuis l'écran d'édition. `name` (technique) et `code` (trinôme, si non saisi) sont générés
     * automatiquement depuis le libellé par RoleNamingService — l'utilisateur ne réfléchit
     * jamais aux contraintes techniques (cf. docblock de classe).
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManageRoles(), 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        $orgId = auth()->user()->organization_id;

        $name = $this->naming->technicalName($data['label']);
        if ($this->naming->technicalNameTaken($name, $orgId)) {
            throw ValidationException::withMessages([
                'label' => "Un rôle équivalent existe déjà (« {$data['label']} ») — choisissez un libellé différent.",
            ]);
        }

        $code = filled($data['code'] ?? null)
            ? $this->naming->normalizeTrinome($data['code'])
            : null;

        if ($code !== null && $this->naming->trinomeTaken($code, $orgId)) {
            throw ValidationException::withMessages([
                'code' => "Ce trinôme est déjà utilisé par un autre rôle (« {$code} »).",
            ]);
        }

        // Role::query()->create() plutôt que Role::create() : le create() statique de Spatie
        // vérifie lui-même l'unicité (name, guard_name) de façon globale, ignorant
        // organization_id — il bloquerait à tort deux organisations différentes qui créent
        // chacune un rôle "chef_agence". La contrainte DB (roles_org_name_guard_unique, cf.
        // migration) reste le vrai garde-fou, scopée correctement par organisation.
        $role = Role::query()->create([
            'organization_id' => $orgId,
            'name' => $name,
            'label' => $data['label'],
            'code' => $code ?? $this->naming->uniqueTrinome($data['label'], $orgId),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return redirect()->route('roles.edit', $role)->with('success', 'Rôle créé — définissez ses permissions ci-dessous.');
    }

    public function edit(Role $role): Response
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->authorizeSameOrganization($role);

        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $resources = $isSuperAdmin
            ? self::RESOURCES
            : array_values(array_filter(self::RESOURCES, fn ($r) => $r !== 'users'));

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label ?? $role->name,
                'code' => $role->code,
                'is_system' => $this->isProtected($role),
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
        $this->authorizeSameOrganization($role);

        $protected = $this->isProtected($role);

        // Libellé et trinôme sont indépendants de la matrice de permissions — traités avant,
        // jamais bloqués par la protection ci-dessous (qui ne porte que sur les permissions et,
        // pour super_admin, sur le libellé/trinôme eux-mêmes).
        $identite = $request->validate([
            'label' => [$protected ? 'prohibited' : 'sometimes', 'string', 'max:100'],
            'code' => [$protected ? 'prohibited' : 'nullable', 'string', 'max:10'],
        ], [
            'label.prohibited' => "Le libellé du rôle système n'est pas modifiable.",
            'code.prohibited' => "Le trinôme du rôle système n'est pas modifiable.",
        ]);

        if (array_key_exists('label', $identite)) {
            $role->label = $identite['label'];
        }

        if (array_key_exists('code', $identite)) {
            $code = filled($identite['code']) ? $this->naming->normalizeTrinome($identite['code']) : null;

            if ($code !== null && $this->naming->trinomeTaken($code, $role->organization_id, $role->id)) {
                throw ValidationException::withMessages([
                    'code' => "Ce trinôme est déjà utilisé par un autre rôle (« {$code} »).",
                ]);
            }

            $role->code = $code;
        }

        $role->save();

        if ($protected) {
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
     * `super_admin` n'est jamais supprimable. Tout autre rôle l'est, y compris `admin_entreprise`
     * — mais jamais aveuglément : un rôle encore attribué à des utilisateurs est refusé pour ne
     * jamais les laisser silencieusement sans rôle, l'appelant doit d'abord les réaffecter.
     */
    public function destroy(Role $role): RedirectResponse
    {
        abort_unless($this->canManageRoles(), 403);
        $this->authorizeSameOrganization($role);

        if ($this->isProtected($role)) {
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

    /**
     * Seule définition de "rôle système protégé" de toute l'application — jamais redupliquée
     * ailleurs (cf. docblock de classe).
     */
    private function isProtected(Role $role): bool
    {
        return $role->name === 'super_admin';
    }

    /**
     * Rôles visibles pour l'utilisateur courant : les rôles système (partagés, organization_id
     * null) + les rôles métier de sa propre organisation — jamais ceux d'une autre organisation.
     */
    private function visibleRoles()
    {
        $orgId = auth()->user()->organization_id;

        return Role::where(function ($q) use ($orgId) {
            $q->whereNull('organization_id')->orWhere('organization_id', $orgId);
        });
    }

    /**
     * Un rôle système (organization_id null) est visible/éditable par tous les admins ; un rôle
     * métier n'appartient qu'à sa propre organisation — jamais accessible à une autre, même en
     * lecture, pour ne jamais laisser fuiter la matrice de permissions d'une organisation vers
     * une autre (cf. migration add_code_and_is_system_to_roles_table).
     */
    private function authorizeSameOrganization(Role $role): void
    {
        abort_if(
            $role->organization_id !== null && $role->organization_id !== auth()->user()->organization_id,
            403
        );
    }

    private function canManageRoles(): bool
    {
        $user = auth()->user();

        return $user && ($user->isSuperAdmin() || $user->hasRole('admin_entreprise'));
    }
}
