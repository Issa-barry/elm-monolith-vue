<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartageParrainService;
use Illuminate\Http\JsonResponse;

/**
 * Partages où le parrain du véhicule reçoit une part positive, avec barème, membres et montants
 * actuels — alimente la confirmation des conséquences de son remplacement ou de son retrait
 * (ParrainDialog.vue, PARRAIN-024). Lecture seule.
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
