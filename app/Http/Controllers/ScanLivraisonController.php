<?php

namespace App\Http\Controllers;

use App\Models\CommandeVente;
use App\Models\TransfertLogistique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Résout une référence de livraison en URL de page backoffice.
 * Utilisé par le scanner backoffice quand il lit le QR code de la livraison mobile.
 */
class ScanLivraisonController extends Controller
{
    public function __invoke(Request $request, string $reference): JsonResponse
    {
        $transfert = TransfertLogistique::where('reference', $reference)->first();
        if ($transfert) {
            return response()->json(['url' => route('logistique.show', $transfert->id)]);
        }

        $commande = CommandeVente::where('reference', $reference)->first();
        if ($commande) {
            return response()->json(['url' => route('ventes.show', $commande->id)]);
        }

        return response()->json(['url' => null, 'message' => 'Référence introuvable.'], 404);
    }
}
