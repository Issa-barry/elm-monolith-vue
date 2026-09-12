<?php

namespace App\Http\Controllers\Produits\Variantes;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Services\ProduitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Modification en masse (prix/codes/statut) — jamais le SKU, généré automatiquement et
 * volontairement non éditable (même règle que UpdateProduitVarianteController). Chaque ligne du
 * payload ne doit porter que les variantes réellement modifiées par l'utilisateur (le frontend
 * calcule le diff), avec l'ensemble de leurs champs éditables — un update() partiel par ligne.
 */
class BulkUpdateProduitVarianteController extends Controller
{
    public function __construct(
        private readonly ProduitService $produitService,
    ) {}

    public function __invoke(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'variantes' => ['required', 'array', 'min:1'],
            'variantes.*.id' => ['required', 'string'],
            'variantes.*.code_barres' => ['nullable', 'string', 'max:100'],
            'variantes.*.prix_usine' => ['nullable', 'integer', 'min:0'],
            'variantes.*.prix_usine_tricycle' => ['nullable', 'integer', 'min:0'],
            'variantes.*.prix_vente' => ['nullable', 'integer', 'min:0'],
            'variantes.*.prix_achat' => ['nullable', 'integer', 'min:0'],
            'variantes.*.cout' => ['nullable', 'integer', 'min:0'],
            'variantes.*.is_active' => ['boolean'],
        ]);

        $champsVariante = ['prix_usine', 'prix_usine_tricycle', 'prix_vente', 'prix_achat'];

        DB::transaction(function () use ($produit, $data, $champsVariante) {
            foreach ($data['variantes'] as $ligne) {
                $variante = ProduitVariante::where('id', $ligne['id'])
                    ->where('produit_id', $produit->id)
                    ->firstOrFail();

                $donneesEffectives = array_merge(
                    Arr::only($variante->getAttributes(), $champsVariante),
                    Arr::only($ligne, $champsVariante),
                );
                $this->produitService->validerPrixSelonType($produit->produitType, $donneesEffectives);

                $variante->update(Arr::except($ligne, ['id']));
            }
        });

        return back()->with('success', count($data['variantes']).' variante(s) mise(s) à jour.');
    }
}
