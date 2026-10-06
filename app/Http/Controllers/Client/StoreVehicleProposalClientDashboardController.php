<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\Client\DuplicateVehicleProposalException;
use App\Http\Controllers\Controller;
use App\Services\Client\VehicleProposalService;
use App\Support\Client\ClientDashboardPayloadBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreVehicleProposalClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardPayloadBuilder $payloadBuilder,
        private readonly VehicleProposalService $proposalService,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$organizationId, $client, $proprietaire, $livreur] = $this->payloadBuilder->resolveActorContext($user);

        $validated = $request->validate(
            VehicleProposalService::validationRules(),
            VehicleProposalService::validationMessages()
        );

        try {
            $this->proposalService->store(
                $user,
                $organizationId,
                $client,
                $proprietaire,
                $livreur,
                $validated,
                $request->file('photo')
            );
        } catch (DuplicateVehicleProposalException) {
            return back()
                ->withErrors([
                    'immatriculation' => 'Une proposition en attente existe deja pour cette immatriculation.',
                ])
                ->withInput();
        }

        return redirect()->route('client.propositions.index')->with('success', 'Votre proposition de vehicule a ete envoyee.');
    }
}
