<?php

namespace App\Http\Controllers\Produits\Options;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produits\UpdateOptionCatalogueRequest;
use App\Models\OptionCatalogue;
use Illuminate\Http\RedirectResponse;

class UpdateOptionCatalogueController extends Controller
{
    public function __invoke(UpdateOptionCatalogueRequest $request, OptionCatalogue $option): RedirectResponse
    {
        $this->authorize('update', $option);

        $option->update($request->validated());

        return back()->with('success', 'Option mise à jour avec succès.');
    }
}
