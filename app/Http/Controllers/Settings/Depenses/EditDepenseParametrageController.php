<?php

namespace App\Http\Controllers\Settings\Depenses;

use App\Http\Controllers\Controller;
use App\Models\DroitCreationDepense;
use App\Models\Site;
use App\Support\Permissions\RoleVisibility;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Droits de création ET de validation des dépenses, par rôle. La classification des types de
 * dépense a déménagé dans le module Dépenses (cf. App\Http\Controllers\Depenses\Types\IndexDepenseTypeController),
 * cette page ne garde que les droits (décision produit 2026-08-24).
 *
 * `is_actif` (droit de CRÉER une dépense) a été ajouté le 2026-09-06 : jusque-là cette colonne
 * n'était écrite par aucun code applicatif — seule la validation (`peut_valider`) était
 * configurable ici, alors que `DroitCreationDepenseService::peutCreer()`/`peutCreerSurSite()`
 * en dépendent depuis toujours. Elle partage `perimetre`/`sites` avec la validation (une seule
 * notion de périmètre d'agences par rôle, cf. peutCreerSurSite()/peutValiderSurSite()).
 */
class EditDepenseParametrageController extends Controller
{
    public function __invoke(): Response
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
}
