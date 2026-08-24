<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DroitAjustementStock;
use App\Models\Parametre;
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
            // Politique globale d'organisation (Gestion des ventes et du stock, DSI 23/08/2026)
            // — conceptuellement distincte des droits d'ajustement manuel ci-dessus : celle-ci
            // dit COMMENT l'organisation se comporte quand une vente dépasse le disponible, pas
            // QUI peut ajuster le stock à la main. Carte séparée dans la page, même contrôleur
            // (route settings/produits) car c'est ici que la DSI a demandé qu'elle vive.
            'autorise_vente_stock_negatif' => Parametre::isVentesAutoriseesSansStock($orgId),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;
        $siteIds = Site::where('organization_id', $orgId)->pluck('id')->all();

        $validated = $request->validate([
            'config' => ['sometimes', 'array'],
            'config.*.role_name' => ['required', 'string'],
            'config.*.peut_augmenter' => ['required', 'boolean'],
            'config.*.peut_diminuer' => ['required', 'boolean'],
            'config.*.perimetre' => ['required', Rule::in(['toutes_agences', 'agences_selectionnees'])],
            'config.*.sites' => ['array'],
            'config.*.sites.*' => ['string', Rule::in($siteIds)],
            'autorise_vente_stock_negatif' => ['sometimes', 'boolean'],
        ]);

        // Deux cartes indépendantes sur cette page, chacune avec son propre bouton
        // Enregistrer — chaque requête ne porte que le champ de la carte concernée.
        if ($request->has('config')) {
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
        }

        if ($request->has('autorise_vente_stock_negatif')) {
            Parametre::setVentesAutoriserStockNegatif($orgId, (bool) $validated['autorise_vente_stock_negatif']);
        }

        return back()->with('success', 'Paramètres produits mis à jour.');
    }
}
