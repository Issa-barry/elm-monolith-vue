<?php

namespace App\Http\Controllers\Contacts\Categories;

use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Services\Contacts\ReferentielCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Nouvel ordre d'une liste (catégories libres ou sous-catégories d'une fonction administrable). */
class OrdonnerCategoriesController extends Controller
{
    public function __invoke(Request $request, ReferentielCategories $referentiel): RedirectResponse
    {
        $this->authorize('gererCategories', CategorieLibre::class);
        abort_if($request->user()->organization_id === null, 403);

        $data = $request->validate([
            'liste' => ['required', 'string', 'max:30'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'string', 'max:26'],
        ]);

        $referentiel->reordonner($request->user(), $data['liste'], array_values($data['ids']));

        return back();
    }
}
