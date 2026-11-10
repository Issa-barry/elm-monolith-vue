<?php

namespace App\Http\Controllers\Contacts\Categories;

use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Models\ContactSousCategorie;
use App\Services\Contacts\ReferentielCategories;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran « Catégories de contacts » (ADR 0060, lot 6a) : sous-catégories par fonction (seules celles
 * des fournisseurs s'administrent ; client et prestataire en lecture seule — décision H) et
 * catégories libres. `contacts.gerer_categories`, organisation de l'utilisateur seulement.
 */
class IndexCategoriesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('gererCategories', CategorieLibre::class);
        $orgId = $request->user()->organization_id;
        abort_if($orgId === null, 403);

        $sousCategories = ContactSousCategorie::where('organization_id', $orgId)
            ->orderBy('ordre')->orderBy('libelle')
            ->get()
            ->groupBy(fn (ContactSousCategorie $s) => $s->categorie->value);

        $sections = array_map(fn ($categorie) => [
            'categorie' => $categorie->value,
            'titre' => $categorie->label(),
            'administrable' => in_array($categorie, ReferentielCategories::SOUS_CATEGORIES_ADMINISTRABLES, true),
            'lignes' => ($sousCategories[$categorie->value] ?? collect())->map(fn (ContactSousCategorie $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'libelle' => $s->libelle,
                'is_system' => $s->is_system,
                'is_active' => $s->is_active,
            ])->values(),
        ], ReferentielCategories::FONCTIONS_AFFICHEES);

        $libres = CategorieLibre::where('organization_id', $orgId)
            ->orderBy('ordre')->orderBy('libelle')
            ->get()
            ->map(fn (CategorieLibre $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'libelle' => $c->libelle,
                'is_system' => false,
                'is_active' => $c->is_active,
            ])
            ->values();

        return Inertia::render('Contacts/Categories/Index', [
            'sections' => $sections,
            'libres' => $libres,
        ]);
    }
}
