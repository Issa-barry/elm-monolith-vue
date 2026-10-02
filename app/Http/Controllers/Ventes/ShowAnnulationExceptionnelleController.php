<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\AnnulationExceptionnelleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowAnnulationExceptionnelleController extends Controller
{
    /**
     * Récapitulatif de ce que l'annulation exceptionnelle va défaire (encaissements, caisse,
     * facture, commissions, cashback, stock), des raisons de refus éventuelles et de l'empreinte
     * des données — renvoyée telle quelle à la demande de code puis à la confirmation.
     */
    public function __invoke(Request $request, CommandeVente $commande_vente, AnnulationExceptionnelleService $service): JsonResponse
    {
        $this->authorize('annulerExceptionnel', $commande_vente);
        // Explicite : le Gate::before du super admin court-circuite la Policy.
        abort_unless($commande_vente->organization_id === $request->user()->organization_id, 403, 'Accès refusé.');

        return response()->json($service->recapitulatif($commande_vente));
    }
}
