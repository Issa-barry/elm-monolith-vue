<?php

namespace App\Http\Controllers\Api\Client\PropositionsVehicule;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Client\PropositionVehiculeResource;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\VehicleProposalService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Expose `ClientDashboardController::storeVehicleProposal()` via l'API SANS
 * dupliquer sa logique — les deux contrôleurs appellent le même
 * `VehicleProposalService` (extrait le 26/08/2026). Seule la mise en forme de
 * la réponse diffère (JSON ici, redirect Inertia côté web).
 */
class IndexPropositionVehiculeController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly VehicleProposalService $proposalService,
    ) {}

    public function __invoke(): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = request()->user();
        $identity = $this->identityResolver->resolve($user);

        return PropositionVehiculeResource::collection(
            $this->proposalService->mine($user->id, $identity->organizationId)
        );
    }
}
