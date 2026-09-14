<?php

namespace App\Http\Controllers\Produits\Categories;

use App\Enums\CategorieStatut;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;

class ToggleCategorieController extends Controller
{
    public function __invoke(Categorie $categorie): RedirectResponse
    {
        $this->authorize('update', $categorie);

        $categorie->update([
            'statut' => $categorie->statut === CategorieStatut::ACTIF ? CategorieStatut::INACTIF : CategorieStatut::ACTIF,
        ]);

        $label = $categorie->statut === CategorieStatut::ACTIF ? 'activée' : 'désactivée';

        return back()->with('success', "Catégorie « {$categorie->nom} » {$label}.");
    }
}
