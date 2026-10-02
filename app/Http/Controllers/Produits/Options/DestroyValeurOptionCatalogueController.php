<?php

namespace App\Http\Controllers\Produits\Options;

use App\Http\Controllers\Controller;
use App\Models\OptionCatalogue;
use Illuminate\Http\RedirectResponse;

class DestroyValeurOptionCatalogueController extends Controller
{
    public function __invoke(OptionCatalogue $option, string $valeur): RedirectResponse
    {
        $this->authorize('update', $option);

        $option->valeurs()->whereKey($valeur)->delete();

        return back()->with('success', 'Valeur supprimée.');
    }
}
