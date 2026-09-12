<?php

namespace App\Http\Controllers\Produits\Variantes;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Services\ProduitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Édite une variante individuelle (prix/codes/statut) — nécessaire dès qu'un produit a
 * plusieurs déclinaisons, puisque ProduitService::mettreAJourSimple() (formulaire principal) ne
 * touche que la variante par défaut. Le SKU n'est volontairement pas éditable ici (généré
 * automatiquement, cf. ProduitVariante::booted()).
 */
class UpdateProduitVarianteController extends Controller
{
    public function __construct(
        private readonly ProduitService $produitService,
    ) {}

    public function __invoke(Request $request, Produit $produit, ProduitVariante $variante): RedirectResponse
    {
        $this->authorize('update', $produit);
        abort_unless($variante->produit_id === $produit->id, 404);

        $data = $request->validate([
            'code_barres' => 'nullable|string|max:100',
            'prix_usine' => 'nullable|integer|min:0',
            'prix_usine_tricycle' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'media_id' => ['nullable', Rule::exists('produit_medias', 'id')->where('produit_id', $produit->id)],
        ]);

        // Valide les prix EFFECTIFS (valeurs déjà sur la variante, écrasées par celles envoyées)
        // — même logique que ProduitService::mettreAJourSimple() pour ne pas rejeter une mise à
        // jour partielle qui ne touche pas au prix.
        $champsVariante = ['prix_usine', 'prix_usine_tricycle', 'prix_vente', 'prix_achat'];
        $donneesEffectives = array_merge(
            Arr::only($variante->getAttributes(), $champsVariante),
            Arr::only($data, $champsVariante),
        );
        $this->produitService->validerPrixSelonType($produit->produitType, $donneesEffectives);

        $variante->update($data);

        return back()->with('success', "Variante « {$variante->libelle} » mise à jour.");
    }
}
