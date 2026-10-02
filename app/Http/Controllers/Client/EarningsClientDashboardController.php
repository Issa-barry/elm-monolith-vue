<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Client\ClientDashboardPayloadBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EarningsClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardPayloadBuilder $payloadBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $dateDebut = $request->input('date_debut') ?: null;
        $dateFin = $request->input('date_fin') ?: null;
        $payload = $this->payloadBuilder->build($request->user(), $dateDebut, $dateFin);

        return Inertia::render('client/Earnings', [
            'actor' => $payload['actor'],
            'vehicules' => $payload['vehicules'],
            'earnings' => $payload['earnings'],
            'earnings_by_vehicule' => $payload['earnings_by_vehicule'],
            'statement' => $payload['statement'],
            'filters' => ['date_debut' => $dateDebut, 'date_fin' => $dateFin],
        ]);
    }
}
