<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DroitCreationDepense;
use App\Models\Site;
use App\Support\Permissions\RoleVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Droits de création ET de validation des dépenses, par rôle. La classification des types de
 * dépense a déménagé dans le module Dépenses (cf. App\Http\Controllers\DepenseTypeController),
 * cette page ne garde que les droits (décision produit 2026-08-24).
 *
 * `is_actif` (droit de CRÉER une dépense) a été ajouté le 2026-09-06 : jusque-là cette colonne
 * n'était écrite par aucun code applicatif — seule la validation (`peut_valider`) était
 * configurable ici, alors que `DroitCreationDepenseService::peutCreer()`/`peutCreerSurSite()`
 * en dépendent depuis toujours. Elle partage `perimetre`/`sites` avec la validation (une seule
 * notion de périmètre d'agences par rôle, cf. peutCreerSurSite()/peutValiderSurSite()).
 */
class DepenseParametrageController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;

        // Scopé à l'organisation courante (rôles système partagés ∪ rôles propres à cette
        // organisation) — Role::orderBy('name')->get() sans filtre exposait ici les rôles
        // personnalisés de TOUTES les organisations de la plateforme (cf. audit § sécurité).
        $roles = RoleVisibility::query($orgId)->orderBy('name')->get(['id', 'name']);
        $sites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']);

        $droits = DroitCreationDepense::where('organization_id', $orgId)
            ->get()
            ->keyBy('role_name');

        $config = $roles->map(fn (Role $role) => [
            'role_name' => $role->name,
            'is_actif' => (bool) ($droits->get($role->name)?->is_actif ?? false),
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

        // Admin Entreprise n'a plus aucun accès automatique depuis le 2026-09-06 : le forçage
        // de peut_valider=true qui vivait ici rendait la case cochée par l'admin sans effet
        // réel (cf. audit rôles/permissions § "contournement automatique") — DroitCreationDepenseService
        // ne le bypasse plus nulle part (seul Super Admin reste illimité), donc sa ligne est
        // désormais traitée exactement comme celle de n'importe quel autre rôle ci-dessous.
        $validated = $request->validate([
            'config' => ['array'],
            'config.*.role_name' => ['required', 'string'],
            'config.*.is_actif' => ['required', 'boolean'],
            'config.*.peut_valider' => ['required', 'boolean'],
            'config.*.perimetre' => ['required', Rule::in(['toutes_agences', 'son_agence', 'agences_selectionnees'])],
            'config.*.sites' => ['array'],
            'config.*.sites.*' => ['string', Rule::in($siteIds)],
            // Obligatoire dès que peut_valider est actif. Super Admin et les rôles sans droit de
            // validation restent sans plafond (null).
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
                    'is_actif' => $item['is_actif'],
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
