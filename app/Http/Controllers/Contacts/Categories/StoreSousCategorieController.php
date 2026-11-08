<?php

namespace App\Http\Controllers\Contacts\Categories;

use App\Enums\ContactCategorie;
use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Services\Contacts\ReferentielCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Création d'une sous-catégorie (lot 6a : fournisseur seulement, revérifié par le service). */
class StoreSousCategorieController extends Controller
{
    public function __invoke(Request $request, ReferentielCategories $referentiel): RedirectResponse
    {
        $this->authorize('gererCategories', CategorieLibre::class);
        abort_if($request->user()->organization_id === null, 403);

        $data = $request->validate([
            'categorie' => ['required', Rule::enum(ContactCategorie::class)],
            'libelle' => ['required', 'string', 'max:100'],
        ]);

        $referentiel->creerSousCategorie($request->user(), ContactCategorie::from($data['categorie']), $data['libelle']);

        return back()->with('success', 'Sous-catégorie créée.');
    }
}
