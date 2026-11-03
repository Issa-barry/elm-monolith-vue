<?php

namespace App\Http\Controllers\Api\Parrain;

use App\Http\Controllers\Api\Parrain\Concerns\ResoutParrainDuCompte;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parrain\PaiementsParrainRequest;
use App\Http\Resources\Api\Parrain\PaiementParrainResource;
use App\Models\CommissionEnveloppePart;
use App\Models\PaiementFichePaiement;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaiementsParrainController extends Controller
{
    use ResoutParrainDuCompte;

    #[Endpoint(
        description: 'Versements reçus par le parrain, du plus récent au plus ancien. Jamais le compte de '
            .'trésorerie ni la personne qui a payé.',
    )]
    public function __invoke(PaiementsParrainRequest $request): AnonymousResourceCollection
    {
        $parrain = $this->parrainDuCompte($request);
        $filtres = $request->filtres();

        $paiements = PaiementFichePaiement::query()
            ->with('fiche.periode')
            ->whereHas('fiche', fn (Builder $q) => $q
                ->where('organization_id', $parrain->organization_id)
                ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)
                ->where('beneficiaire_id', $parrain->id))
            ->when($filtres['date_debut'], fn (Builder $q, string $date) => $q->whereDate('date_paiement', '>=', $date))
            ->when($filtres['date_fin'], fn (Builder $q, string $date) => $q->whereDate('date_paiement', '<=', $date))
            ->orderByDesc('date_paiement')
            ->orderByDesc('created_at')
            ->paginate($request->perPage())
            ->withQueryString();

        return PaiementParrainResource::collection($paiements)->additional(['filters' => $filtres]);
    }
}
