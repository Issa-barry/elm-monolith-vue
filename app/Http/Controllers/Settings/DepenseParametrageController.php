<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DroitCreationDepense;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Validation des dépenses — droits de validation par rôle uniquement. La
 * classification des types de dépense a déménagé dans le module Dépenses
 * (cf. App\Http\Controllers\DepenseTypeController), cette page ne garde que
 * les règles/droits de validation (décision produit 2026-08-24).
 */
class DepenseParametrageController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;

        $roles = Role::orderBy('name')->get(['id', 'name']);
        $sites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']);

        $droits = DroitCreationDepense::where('organization_id', $orgId)
            ->get()
            ->keyBy('role_name');

        $config = $roles->map(fn (Role $role) => [
            'role_name' => $role->name,
            'peut_valider' => (bool) ($droits->get($role->name)?->peut_valider ?? false),
            'perimetre' => $droits->get($role->name)?->perimetre ?? 'toutes_agences',
            'sites' => $droits->get($role->name)?->sites ?? [],
            'plafond_validation' => $droits->get($role->name)?->plafond_validation !== null
                ? (float) $droits->get($role->name)->plafond_validation
                : null,
        ]);

        return Inertia::render('settings/DepenseParametrage', [
            'config' => $config,
            'sites' => $sites,
        ]);
    }

    public function updateDroits(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;
        $siteIds = Site::where('organization_id', $orgId)->pluck('id')->all();

        // Admin Entreprise garde un accès automatique (permission de validation,
        // périmètre d'agences — cf. DroitCreationDepenseService::peutValiderSurSite())
        // mais est désormais soumis au plafond de montant comme n'importe quel
        // rôle (seul Super Admin reste illimité, décision produit 04/09/2026,
        // cf. docs/depenses-validation.md). On force donc peut_valider=true pour
        // sa ligne avant validation, indépendamment de ce qu'envoie le
        // frontend, pour que le plafond lui soit imposé comme obligatoire
        // ci-dessous et que la ligne reste trouvable par droitValidationPour().
        $config = $request->input('config', []);
        foreach ($config as $i => $item) {
            if (($item['role_name'] ?? null) === 'admin_entreprise') {
                $config[$i]['peut_valider'] = true;
            }
        }
        $request->merge(['config' => $config]);

        $validated = $request->validate([
            'config' => ['array'],
            'config.*.role_name' => ['required', 'string'],
            'config.*.peut_valider' => ['required', 'boolean'],
            'config.*.perimetre' => ['required', Rule::in(['toutes_agences', 'son_agence', 'agences_selectionnees'])],
            'config.*.sites' => ['array'],
            'config.*.sites.*' => ['string', Rule::in($siteIds)],
            // Obligatoire dès que peut_valider est actif (donc toujours pour
            // Admin Entreprise, cf. coercition ci-dessus). Super Admin et les
            // rôles sans droit de validation restent sans plafond (null).
            'config.*.plafond_validation' => ['nullable', 'numeric', 'min:0', 'required_if:config.*.peut_valider,true'],
        ], [
            'config.*.plafond_validation.required_if' => 'Le plafond de validation est obligatoire pour un rôle autorisé à valider.',
        ]);

        foreach ($validated['config'] ?? [] as $item) {
            $sites = $item['perimetre'] === 'agences_selectionnees'
                ? array_values(array_unique($item['sites'] ?? []))
                : null;

            DroitCreationDepense::updateOrCreate(
                ['organization_id' => $orgId, 'role_name' => $item['role_name']],
                [
                    'perimetre' => $item['perimetre'],
                    'sites' => $sites,
                    'peut_valider' => $item['peut_valider'],
                    'plafond_validation' => $item['peut_valider'] ? $item['plafond_validation'] : null,
                ]
            );
        }

        return back()->with('success', 'Droits de validation mis à jour.');
    }
}
