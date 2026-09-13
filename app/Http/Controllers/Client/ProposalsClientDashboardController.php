<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Client\ClientDashboardPayloadBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProposalsClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardPayloadBuilder $payloadBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $this->payloadBuilder->build($request->user());

        return Inertia::render('client/VehicleProposals', [
            'actor' => $payload['actor'],
            'vehicle_proposals' => $payload['vehicle_proposals'],
            'type_vehicule_options' => $payload['type_vehicule_options'],
        ]);
    }
}
