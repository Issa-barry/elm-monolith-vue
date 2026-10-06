<?php

namespace App\Http\Controllers\Produits\Categories;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;

class DestroyCategorieController extends Controller
{
    public function __invoke(Categorie $categorie): RedirectResponse
    {
        $this->authorize('delete', $categorie);

        if ($categorie->is_used) {
            return back()->withErrors([
                'delete' => 'Cette catégorie est utilisée par des produits, possède des sous-catégories, ou sert de référence de capacité sur un véhicule. Désactivez-la plutôt que de la supprimer.',
            ]);
        }

        $categorie->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }
}
