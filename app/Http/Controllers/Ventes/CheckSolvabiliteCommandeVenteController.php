<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Services\SolvabiliteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckSolvabiliteCommandeVenteController extends Controller
{
    public function __construct(private readonly SolvabiliteService $solvabiliteService) {}

    /**
     * Simple miroir de lecture de SolvabiliteService::evaluer() — jamais de logique de calcul
     * ici, jamais de blocage : ce n'est qu'un aperçu pour guider l'utilisateur AVANT
     * soumission. Le vrai gate est enforceImpayesBlocking() sur StoreCommandeVenteController,
     * sur le même service.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'vehicule_id' => 'nullable|exists:vehicules,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $orgId = auth()->user()->organization_id;

        return response()->json($this->solvabiliteService->evaluer(
            $orgId,
            $request->input('vehicule_id'),
            $request->input('client_id'),
        ));
    }
}
