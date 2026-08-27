<?php

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\Client\DuplicateVehicleProposalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\StoreVehicleProposalRequest;
use App\Http\Resources\Api\Client\PropositionVehiculeResource;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\VehicleProposalService;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Expose `ClientDashboardController::storeVehicleProposal()` via l'API SANS
 * dupliquer sa logique — les deux contrôleurs appellent le même
 * `VehicleProposalService` (extrait le 26/08/2026). Seule la mise en forme de
 * la réponse diffère (JSON ici, redirect Inertia côté web).
 */
class PropositionsVehiculeController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly VehicleProposalService $proposalService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = request()->user();
        $identity = $this->identityResolver->resolve($user);

        return PropositionVehiculeResource::collection(
            $this->proposalService->mine($user->id, $identity->organizationId)
        );
    }

    #[Endpoint(
        description: 'Requête `multipart/form-data` (`photo` est un fichier image, 5 Mo max, '
            .'converti en WebP côté serveur). `immatriculation` est normalisée en MAJUSCULES. '
            .'`422` si une proposition **en attente** existe déjà pour cette immatriculation '
            .'(même règle que l\'espace client Inertia, même service partagé '
            .'`VehicleProposalService` — jamais un moteur dupliqué).',
    )]
    public function store(StoreVehicleProposalRequest $request): JsonResponse|PropositionVehiculeResource
    {
        /** @var User $user */
        $user = $request->user();
        $identity = $this->identityResolver->resolve($user);

        try {
            $proposition = $this->proposalService->store(
                $user,
                $identity->organizationId,
                $identity->client,
                $identity->proprietaire,
                $identity->livreur,
                $request->validated(),
                $request->file('photo')
            );
        } catch (DuplicateVehicleProposalException $e) {
            throw ValidationException::withMessages([
                'immatriculation' => "Une proposition en attente existe déjà pour l'immatriculation {$e->immatriculation}.",
            ]);
        }

        return (new PropositionVehiculeResource($proposition))
            ->response()
            ->setStatusCode(201);
    }
}
