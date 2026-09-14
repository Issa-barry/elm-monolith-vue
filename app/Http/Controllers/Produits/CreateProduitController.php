<?php

namespace App\Http\Controllers\Produits;

use App\Enums\ProduitStatut;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\OptionCatalogue;
use App\Models\Parametre;
use App\Models\Produit;
use App\Support\Produits\ProduitFormOptions;
use Inertia\Inertia;
use Inertia\Response;

class CreateProduitController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('create', Produit::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Produits/Create', [
            'types' => ProduitFormOptions::types($orgId),
            'statuts' => ProduitStatut::options(),
            'categories' => Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'parent_id']),
            'optionsCatalogue' => OptionCatalogue::where('organization_id', $orgId)
                ->orderBy('position')->orderBy('nom')
                ->with('valeurs:id,option_catalogue_id,valeur,hex')
                ->get(['id', 'nom']),
            'fournisseurs' => ProduitFormOptions::fournisseurs($orgId),
            'limites' => ProduitFormOptions::limites($orgId),
            'seuilOrganisationDefaut' => Parametre::getSeuilStockFaible($orgId),
        ]);
    }
}
