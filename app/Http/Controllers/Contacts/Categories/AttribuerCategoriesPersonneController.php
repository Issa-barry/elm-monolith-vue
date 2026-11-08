<?php

namespace App\Http\Controllers\Contacts\Categories;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Services\Contacts\ReferentielCategories;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Catégories libres d'une fiche Personne (ADR 0060, lot 6a) : `contacts.update` et fiche visible
 * (404 sinon, super_admin compris — Gate::before). La liste envoyée remplace la liste portée.
 */
class AttribuerCategoriesPersonneController extends Controller
{
    public function __invoke(Request $request, Personne $personne, VisibiliteContacts $visibilite, ReferentielCategories $referentiel): RedirectResponse
    {
        $this->authorize('modifierCategories', Personne::class);
        abort_unless($visibilite->personneVisible($personne, $request->user()), 404);

        $data = $request->validate([
            'categorie_ids' => ['present', 'array', 'max:50'],
            'categorie_ids.*' => ['required', 'string', 'max:26'],
        ]);

        $referentiel->attribuer($request->user(), $personne, array_values($data['categorie_ids']));

        return back()->with('success', 'Catégories mises à jour.');
    }
}
