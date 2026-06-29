<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CategorieDepense;
use App\Http\Controllers\Controller;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class DepenseParametrageController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;

        // Types de dépense
        $types = DepenseType::where('organization_id', $orgId)
            ->ordered()
            ->get()
            ->map(fn (DepenseType $t) => [
                'id' => $t->id,
                'libelle' => $t->libelle,
                'description' => $t->description,
                'categorie' => $t->categorie->value,
                'categorie_label' => $t->categorie->label(),
                'commentaire_obligatoire' => $t->commentaire_obligatoire,
                'justificatif_obligatoire' => $t->justificatif_obligatoire,
                'type_paie' => $t->type_paie,
                'is_active' => $t->is_active,
                'depenses_count' => $t->depenses()->count(),
            ]);

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
        ]);

        return Inertia::render('settings/DepenseParametrage', [
            'types' => $types,
            'categories' => CategorieDepense::options(),
            'config' => $config,
            'sites' => $sites,
        ]);
    }

    public function updateDroits(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;
        $siteIds = Site::where('organization_id', $orgId)->pluck('id')->all();

        $validated = $request->validate([
            'config' => ['array'],
            'config.*.role_name' => ['required', 'string'],
            'config.*.peut_valider' => ['required', 'boolean'],
            'config.*.perimetre' => ['required', Rule::in(['toutes_agences', 'son_agence', 'agences_selectionnees'])],
            'config.*.sites' => ['array'],
            'config.*.sites.*' => ['string', Rule::in($siteIds)],
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
                ]
            );
        }

        return back()->with('success', 'Droits de validation mis à jour.');
    }
}
