<?php

namespace App\Http\Controllers\Produits\Options;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produits\StoreOptionCatalogueValeurRequest;
use App\Models\OptionCatalogue;
use Illuminate\Http\RedirectResponse;

class StoreValeurOptionCatalogueController extends Controller
{
    public function __invoke(StoreOptionCatalogueValeurRequest $request, OptionCatalogue $option): RedirectResponse
    {
        $this->authorize('update', $option);

        $position = $option->valeurs()->max('position');

        $valeur = $option->valeurs()->create([
            'valeur' => $request->validated('valeur'),
            'hex' => $request->validated('hex'),
            'position' => $position === null ? 0 : $position + 1,
        ]);

        return back()
            ->with('success', 'Valeur ajoutée avec succès.')
            ->with('created_option_catalogue_valeur_id', $valeur->id);
    }
}
