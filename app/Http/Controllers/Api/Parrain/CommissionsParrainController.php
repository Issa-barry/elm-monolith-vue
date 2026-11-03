<?php

namespace App\Http\Controllers\Api\Parrain;

use App\Http\Controllers\Api\Parrain\Concerns\ResoutParrainDuCompte;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parrain\CommissionsParrainRequest;
use App\Http\Resources\Api\Parrain\CommissionParrainResource;
use App\Services\Comptes\EspaceParrainService;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommissionsParrainController extends Controller
{
    use ResoutParrainDuCompte;

    #[Endpoint(
        description: 'Parts du parrain, une ligne par opération, de la plus récente à la plus ancienne, '
            .'sans les commissions annulées. `resume` porte les totaux des filtres appliqués, sur toutes '
            .'les pages. Un `vehicule_id` d\'un véhicule sans part de ce parrain renvoie une liste vide.',
    )]
    public function __invoke(CommissionsParrainRequest $request, EspaceParrainService $espace): AnonymousResourceCollection
    {
        $parrain = $this->parrainDuCompte($request);
        $filtres = $request->filtres();
        $parts = $espace->partsFiltrees($parrain, $filtres);

        return CommissionParrainResource::collection(
            $espace->pourListe(clone $parts)->paginate($request->perPage())->withQueryString(),
        )->additional([
            'resume' => $espace->totaux($parts),
            'filters' => $filtres,
        ]);
    }
}
