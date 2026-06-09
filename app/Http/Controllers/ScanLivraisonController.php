<?php

namespace App\Http\Controllers;

use App\Models\CommandeVente;
use App\Models\TransfertLogistique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Résout une référence de livraison en URL de page backoffice.
 *
 * - Requête navigateur (scan USB via window.location.href) → redirect 302
 * - Requête JSON (fetch depuis useScanInterceptor Cas 3)  → { url }
 */
class ScanLivraisonController extends Controller
{
    public function __invoke(Request $request, string $reference): JsonResponse|RedirectResponse
    {
        $url = $this->resolveUrl($reference);

        if ($url === null) {
            return $request->expectsJson()
                ? response()->json(['url' => null, 'message' => 'Référence introuvable.'], 404)
                : redirect()->route('dashboard')->with('error', "Livraison « {$reference} » introuvable.");
        }

        return $request->expectsJson()
            ? response()->json(['url' => $url])
            : redirect($url);
    }

    private function resolveUrl(string $reference): ?string
    {
        $transfert = TransfertLogistique::where('reference', $reference)->first();
        if ($transfert) {
            return route('logistique.show', $transfert->id);
        }

        $commande = CommandeVente::where('reference', $reference)->first();
        if ($commande) {
            return route('ventes.show', $commande->id);
        }

        return null;
    }
}
