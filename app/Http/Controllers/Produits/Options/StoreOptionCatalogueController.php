<?php

namespace App\Http\Controllers\Produits\Options;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produits\StoreOptionCatalogueRequest;
use App\Models\OptionCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class StoreOptionCatalogueController extends Controller
{
    public function __invoke(StoreOptionCatalogueRequest $request): RedirectResponse
    {
        $this->authorize('create', OptionCatalogue::class);

        $option = DB::transaction(function () use ($request) {
            $option = OptionCatalogue::create([
                'nom' => $request->validated('nom'),
                'position' => $request->validated('position') ?? 0,
                'organization_id' => auth()->user()->organization_id,
            ]);

            foreach ($request->input('valeurs', []) as $index => $valeur) {
                $option->valeurs()->create(['valeur' => $valeur, 'position' => $index]);
            }

            return $option;
        });

        // Permet à la création rapide depuis un formulaire Produit (modale, sans navigation)
        // de sélectionner automatiquement l'option tout juste créée — cf. OptionCatalogueSelect.vue.
        return back()
            ->with('success', 'Option créée avec succès.')
            ->with('created_option_catalogue_id', $option->id);
    }
}
