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
 * Paramètres → Achats (ADR 0021) : par rôle, le périmètre « Peut acheter pour » (agences pour
 * lesquelles le rôle crée, voit et valide des bons de commande), le plafond de validation (vide =
 * le rôle ne valide rien) et le droit de valider ses propres bons (séparation des tâches sinon). Les permissions `achats.*` se cochent dans l'écran Rôles. Tous les
 * rôles se configurent ici à l'identique, super administrateur compris : sans règle, aucun accès.
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
                'peut_valider_ses_propres_bons' => (bool) ($regle?->peut_valider_ses_propres_bons ?? false),
                'peut_valider_ses_propres_factures' => (bool) ($regle?->peut_valider_ses_propres_factures ?? false),                'perimetre' => $regle?->perimetre ?? 'toutes_agences',
                'sites' => $regle?->sites ?? [],
            ];
        })->values();

        return Inertia::render('settings/AchatParametrage', [
            'config' => $config,
            'sites' => Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']),
        ]);
    }
}
