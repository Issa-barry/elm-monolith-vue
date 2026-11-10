<?php

namespace App\Http\Controllers\Contacts\Categories;

use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Services\Contacts\ReferentielCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Renommer, désactiver ou réactiver une catégorie libre. */
class UpdateCategorieLibreController extends Controller
{
    public function __invoke(Request $request, CategorieLibre $categorieLibre, ReferentielCategories $referentiel): RedirectResponse
    {
        $this->authorize('gererCategories', CategorieLibre::class);

        $data = $request->validate([
            'libelle' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $referentiel->modifierCategorieLibre($request->user(), $categorieLibre, $data);

        return back()->with('success', 'Catégorie mise à jour.');
    }
}
