<?php

namespace App\Http\Controllers\Api\Parrain;

use App\Http\Controllers\Api\Parrain\Concerns\ResoutParrainDuCompte;
use App\Http\Controllers\Controller;
use App\Services\Comptes\EspaceParrainService;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyntheseParrainController extends Controller
{
    use ResoutParrainDuCompte;

    #[Endpoint(
        description: 'Identité et totaux du parrain relié au compte par un responsable (ADR 0050). '
            .'`404` si aucun parrain n\'est rattaché. `total_genere` = `en_attente_periode` + `payable` '
            .'+ `deja_paye`. Le parrain vient du compte authentifié, jamais d\'un paramètre.',
    )]
    public function __invoke(Request $request, EspaceParrainService $espace): JsonResponse
    {
        $parrain = $this->parrainDuCompte($request);

        return response()->json([
            'parrain' => [
                'id' => $parrain->id,
                'nom_complet' => $parrain->nom_complet,
                'telephone' => $parrain->telephone,
                'is_active' => (bool) $parrain->is_active,
            ],
            'nb_vehicules' => $parrain->vehicules()->count(),
            'totaux' => $espace->totaux($espace->parts($parrain)),
        ]);
    }
}
