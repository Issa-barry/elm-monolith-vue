<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DroitAjustementStock;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class StockAjustementController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;

        $roles = Role::orderBy('name')->get(['id', 'name']);
        $sites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']);

        $droits = DroitAjustementStock::where('organization_id', $orgId)
            ->get()
            ->keyBy('role_name');

        $config = $roles->map(fn (Role $role) => [
            'role_name' => $role->name,
            'peut_augmenter' => (bool) ($droits->get($role->name)?->peut_augmenter ?? false),
            'peut_diminuer' => (bool) ($droits->get($role->name)?->peut_diminuer ?? false),
            'perimetre' => $droits->get($role->name)?->perimetre ?? 'toutes_agences',
            'sites' => $droits->get($role->name)?->sites ?? [],
        ]);

        return Inertia::render('settings/ProduitParametrage', [
            'config' => $config,
            'sites' => $sites,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;
        $siteIds = Site::where('organization_id', $orgId)->pluck('id')->all();

        $validated = $request->validate([
            'config' => ['array'],
            'config.*.role_name' => ['required', 'string'],
            'config.*.peut_augmenter' => ['required', 'boolean'],
            'config.*.peut_diminuer' => ['required', 'boolean'],
            'config.*.perimetre' => ['required', Rule::in(['toutes_agences', 'agences_selectionnees'])],
            'config.*.sites' => ['array'],
            'config.*.sites.*' => ['string', Rule::in($siteIds)],
        ]);

        foreach ($validated['config'] ?? [] as $item) {
            $sites = $item['perimetre'] === 'agences_selectionnees'
                ? array_values(array_unique($item['sites'] ?? []))
                : null;

            DroitAjustementStock::updateOrCreate(
                ['organization_id' => $orgId, 'role_name' => $item['role_name']],
                [
                    'perimetre' => $item['perimetre'],
                    'sites' => $sites,
                    'peut_augmenter' => $item['peut_augmenter'],
                    'peut_diminuer' => $item['peut_diminuer'],
                ]
            );
        }

        return back()->with('success', 'Configuration de l\'ajustement de stock mise à jour.');
    }
}
