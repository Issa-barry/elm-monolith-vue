<?php

namespace App\Http\Controllers\Settings\Achats;

use App\Http\Controllers\Controller;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Support\Permissions\RoleVisibility;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Paramètres → Achats : plafond de validation des bons de commande par rôle, et agences couvertes
 * (ADR 0021). La permission `achats.valider` se coche dans l'écran Rôles ; cette page ne fait que
 * la borner. Le super administrateur se configure ici comme tout autre rôle : sans règle, il ne
 * valide aucun bon de commande (décision du 07/10/2026).
 */
class EditAchatParametrageController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;

        $roles = RoleVisibility::query($orgId)
            ->with('permissions')
            ->orderBy('name')
            ->get();

        $regles = RegleValidationRole::where('organization_id', $orgId)
            ->where('domaine', RegleValidationRole::DOMAINE_ACHATS)
            ->get()
            ->keyBy('role_name');

        $config = $roles->map(function (Role $role) use ($regles) {
            $regle = $regles->get($role->name);

            return [
                'role_name' => $role->name,
                'role_label' => $role->label ?: $role->name,
                'a_permission' => $role->permissions->contains('name', 'achats.valider'),
                'actif' => $regle !== null,
                'plafond' => $regle?->plafond !== null ? (float) $regle->plafond : null,
                'plafond_illimite' => (bool) ($regle?->plafond_illimite ?? false),
                'perimetre' => $regle?->perimetre ?? 'toutes_agences',
                'sites' => $regle?->sites ?? [],
            ];
        })->values();

        return Inertia::render('settings/AchatParametrage', [
            'config' => $config,
            'sites' => Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']),
        ]);
    }
}
