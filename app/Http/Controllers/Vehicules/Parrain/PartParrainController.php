<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartageParrainService;
use App\Services\Commission\PartParrainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Grille « Définir la part du parrain » (PARRAIN-021) : chaque partage où le parrain figure, avec
 * barème, livreurs et montants actuels. Lecture seule ; même droit que l'enregistrement.
 */
class PartParrainController extends Controller
{
    public function __invoke(Request $request, Vehicule $vehicule): JsonResponse
    {
        $this->authorize('update', $vehicule);
        abort_unless($request->user()->can('equipes-livraison.update'), 403);

        return response()->json([
            'parrain_id' => $vehicule->parrain_id,
            'statut' => PartParrainService::statut($vehicule),
            'groupes' => PartageParrainService::groupesDuParrain($vehicule),
            // PARRAIN-023 : date de début des droits au plus tôt (lendemain de la dernière période
            // clôturée), null sans limite.
            // Répartition planifiée (date de début future) que l'enregistrement remplacera.
            'planification' => PartParrainService::planification($vehicule),
            'date_effet_min' => PartParrainService::dateMinimaleDroits($vehicule)?->toDateString(),
            // « Aujourd'hui » du serveur (fuseau de l'application) : date par défaut et référence
            // passé / futur de l'écran, jamais l'horloge du poste de l'utilisateur.
            'aujourdhui' => Carbon::today()->toDateString(),
        ]);
    }
}
