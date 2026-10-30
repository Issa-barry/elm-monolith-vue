<?php

namespace App\Http\Controllers\Ventes\Regularisation;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\Regularisation\RegularisationVenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Envoie le code de confirmation à l'adresse de l'utilisateur authentifié (ADR 0043). */
class DemanderCodeRegularisationVenteController extends Controller
{
    public function __invoke(Request $request, CommandeVente $commande_vente, RegularisationVenteService $service): JsonResponse
    {
        $this->authorize('regulariserApresEncaissement', $commande_vente);

        $data = $request->validate(
            RecapitulatifRegularisationVenteController::reglesConfirmation(),
            RecapitulatifRegularisationVenteController::messages(),
        );

        return response()->json($service->demanderCode(
            $commande_vente,
            $request->user(),
            $data['lignes'],
            $data['traitement_excedent'] ?? null,
            $data['motif'],
            $data['empreinte'],
        ));
    }
}
