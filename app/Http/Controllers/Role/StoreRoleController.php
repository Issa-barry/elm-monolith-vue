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

class StoreRoleController extends Controller
{
    public function __construct(private readonly RoleNamingService $naming) {}

    /**
     * Un rôle créé ici démarre toujours sans aucune permission (jamais une copie d'un rôle
     * existant) — principe du moindre privilège : l'admin les accorde ensuite explicitement
     * depuis l'écran d'édition. `name` (technique) et `code` (trinôme, si non saisi) sont générés
     * automatiquement depuis le libellé par RoleNamingService — l'utilisateur ne réfléchit
     * jamais aux contraintes techniques.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(RoleAccess::canManageRoles(), 403);

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
}
