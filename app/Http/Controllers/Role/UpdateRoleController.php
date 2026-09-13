<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use App\Services\RoleNamingService;
use App\Support\Permissions\RoleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UpdateRoleController extends Controller
{
    public function __construct(private readonly RoleNamingService $naming) {}

    public function __invoke(Request $request, Role $role): RedirectResponse
    {
        $user = auth()->user();

        abort_unless(RoleAccess::canManageRole($role), 403);
        RoleAccess::authorizeSameOrganization($role);

        $protected = RoleAccess::isProtected($role);

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

        // admin_entreprise ne peut pas toucher les permissions users.* (cachées de l'UI, cf.
        // ressource 'users' filtrée dans EditRoleController) → préservées telles quelles depuis
        // le rôle, le reste (matrice CRUD + standalone) vient intégralement du formulaire
        // désormais que PermissionCatalog::STANDALONE est, lui aussi, éditable dans la matrice.
        if (! $user->isSuperAdmin()) {
            $usersFromRole = $role->permissions()
                ->pluck('name')
                ->filter(fn ($p) => str_starts_with($p, 'users.'))
                ->values()
                ->toArray();

            $permissions = array_values(array_filter($permissions, fn ($p) => ! str_starts_with($p, 'users.')));
            $permissions = array_merge($permissions, $usersFromRole);
        }

        $role->syncPermissions($permissions);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return back()->with('success', 'Rôle mis à jour avec succès.');
    }
}
