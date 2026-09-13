<?php

namespace App\Http\Controllers\Produits\Variantes;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Support\Produits\ProduitVarianteOptionsFormatter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Éditeur groupé façon Shopify — vue dense de toutes les variantes d'un produit, avec
 * sélection multiple et modification en masse (cf. BulkUpdateProduitVarianteController). Le
 * stock n'y est volontairement pas éditable : il reste soumis au flux motif-tracké "Ajuster le
 * stock" (ProduitController::ajusterStock()) pour préserver la traçabilité des mouvements — ce
 * n'est pas un oubli.
 */
class IndexProduitVarianteController extends Controller
{
    public function __invoke(Produit $produit): Response
    {
        $this->authorize('update', $produit);

        $produit->load(['variantes.valeurs.option', 'produitType']);

        return Inertia::render('Produits/Variantes/Index', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'type_nom' => $produit->produitType?->nom,
                'prix_usine_requis' => (bool) $produit->produitType?->prix_usine_requis,
                // cf. ProduitController::typesOptions() : achetable/vendable pilotent la
                // visibilité de prix_achat/prix_vente, indépendamment de leur caractère
                // obligatoire.
                'achetable' => (bool) ($produit->produitType?->achetable ?? true),
                'vendable' => (bool) ($produit->produitType?->vendable ?? true),
            ],
            'variantes' => $produit->variantes->map(fn (ProduitVariante $v) => [
                'id' => $v->id,
                'libelle' => $v->libelle,
                'sku' => $v->sku,
                'code_barres' => $v->code_barres,
                'prix_usine' => $v->prix_usine,
                'prix_vente' => $v->prix_vente,
                'prix_achat' => $v->prix_achat,
                'cout' => $v->cout,
                'is_default' => $v->is_default,
                'is_active' => $v->is_active,
                'options' => ProduitVarianteOptionsFormatter::pour($v),
            ]),
        ]);
    }
}
