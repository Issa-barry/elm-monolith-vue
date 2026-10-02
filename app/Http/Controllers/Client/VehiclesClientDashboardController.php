<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Client\ClientDashboardPayloadBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehiclesClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardPayloadBuilder $payloadBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $this->payloadBuilder->build($request->user());

        return Inertia::render('client/Vehicles', [
            'actor' => $payload['actor'],
            'owner_vehicules' => $payload['owner_vehicules'],
            'type_vehicule_options' => $payload['type_vehicule_options'],
        ]);
    }
}
