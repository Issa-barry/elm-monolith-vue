<?php

namespace App\Http\Controllers\Contacts\Categories;

use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Models\ContactSousCategorie;
use App\Services\Contacts\ReferentielCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Renommer, désactiver ou réactiver une sous-catégorie non système d'une fonction administrable. */
class UpdateSousCategorieController extends Controller
{
    public function __invoke(Request $request, ContactSousCategorie $sousCategorie, ReferentielCategories $referentiel): RedirectResponse
    {
        $this->authorize('gererCategories', CategorieLibre::class);

        $data = $request->validate([
            'libelle' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $referentiel->modifierSousCategorie($request->user(), $sousCategorie, $data);

        return back()->with('success', 'Sous-catégorie mise à jour.');
    }
}
