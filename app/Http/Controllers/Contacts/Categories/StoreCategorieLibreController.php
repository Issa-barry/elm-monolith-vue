<?php

namespace App\Http\Controllers\Contacts\Categories;

use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Services\Contacts\ReferentielCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Création d'une catégorie libre (ex. Partenaire), sans effet métier. */
class StoreCategorieLibreController extends Controller
{
    public function __invoke(Request $request, ReferentielCategories $referentiel): RedirectResponse
    {
        $this->authorize('gererCategories', CategorieLibre::class);
        abort_if($request->user()->organization_id === null, 403);

        $data = $request->validate(['libelle' => ['required', 'string', 'max:100']]);

        $referentiel->creerCategorieLibre($request->user(), $data['libelle']);

        return back()->with('success', 'Catégorie créée.');
    }
}
