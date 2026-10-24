<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartageParrainService;
use Illuminate\Http\JsonResponse;

/**
 * Partages à refaire pour remplacer ou retirer le parrain du véhicule (ceux où il reçoit une part
 * positive), avec barème, membres et montants actuels — alimente l'étape « Nouvelle répartition »
 * de ParrainDialog.vue. Lecture seule.
 */
class RepartitionsParrainController extends Controller
{
    public function __invoke(Vehicule $vehicule): JsonResponse
    {
        $this->authorize('update', $vehicule);

        return response()->json([
            'groupes' => PartageParrainService::groupesARepartir($vehicule),
        ]);
    }
}
