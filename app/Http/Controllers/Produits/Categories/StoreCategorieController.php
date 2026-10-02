<?php

namespace App\Http\Controllers\Produits\Categories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produits\StoreCategorieRequest;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;

class StoreCategorieController extends Controller
{
    public function __invoke(StoreCategorieRequest $request): RedirectResponse
    {
        $this->authorize('create', Categorie::class);

        $categorie = Categorie::create([...$request->validated(), 'organization_id' => auth()->user()->organization_id]);

        // Permet à la création rapide depuis un formulaire Produit (modale, sans navigation)
        // de sélectionner automatiquement la catégorie tout juste créée — cf. CategorieSelect.vue.
        return back()
            ->with('success', 'Catégorie créée avec succès.')
            ->with('created_categorie_id', $categorie->id);
    }
}
