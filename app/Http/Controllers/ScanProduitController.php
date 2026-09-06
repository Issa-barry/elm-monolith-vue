<?php

namespace App\Http\Controllers;

use App\Models\Produit;
use App\Models\ProduitVariante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Résout un code-barres/SKU produit scanné en URL de fiche backoffice.
 *
 * Même famille que ScanUserController/ScanLivraisonController (résolution "code
 * scanné → URL"), mais scopée à l'organisation de l'appelant et gardée par la
 * permission produits.read (ProduitPolicy::viewAny) — contrairement aux deux autres,
 * un code-barres produit expose des données commerciales (prix indirectement via la
 * fiche produit), pas seulement une redirection vers une fiche déjà elle-même protégée
 * par sa propre policy.
 */
class ScanProduitController extends Controller
{
    public function __invoke(Request $request, string $code): JsonResponse
    {
        $this->authorize('viewAny', Produit::class);

        $organizationId = $request->user()->organization_id;

        $variante = ProduitVariante::where('organization_id', $organizationId)
            ->where('code_barres', $code)
            ->first()
            ?? ProduitVariante::where('organization_id', $organizationId)
                ->where('sku', mb_strtoupper(trim($code)))
                ->first();

        if (! $variante) {
            return response()->json(['url' => null, 'message' => 'Produit introuvable.'], 404);
        }

        return response()->json(['url' => route('produits.show', $variante->produit_id)]);
    }
}
