<?php

namespace App\Http\Controllers\Produits\Categories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produits\UpdateCategorieRequest;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;

class UpdateCategorieController extends Controller
{
    public function __invoke(UpdateCategorieRequest $request, Categorie $categorie): RedirectResponse
    {
        $this->authorize('update', $categorie);

        $categorie->update($request->validated());

        return back()->with('success', 'Catégorie mise à jour avec succès.');
    }
}
