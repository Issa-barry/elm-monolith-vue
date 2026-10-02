<?php

namespace App\Http\Controllers\Produits\Categories;

use App\Enums\CategorieStatut;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use Inertia\Inertia;
use Inertia\Response;

class IndexCategorieController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', Categorie::class);

        $orgId = auth()->user()->organization_id;

        $categories = Categorie::where('organization_id', $orgId)
            ->orderBy('position')
            ->orderBy('nom')
            ->withCount('produits')
            ->get()
            ->map(fn (Categorie $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'reference' => $c->reference,
                'description' => $c->description,
                'statut' => $c->statut->value,
                'statut_label' => $c->statut->label(),
                'parent_id' => $c->parent_id,
                'position' => $c->position,
                'produits_count' => $c->produits_count,
            ]);

        return Inertia::render('Produits/Categories/Index', [
            'categories' => $categories,
            'statuts' => CategorieStatut::options(),
        ]);
    }
}
