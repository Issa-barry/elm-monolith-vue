<?php

namespace App\Http\Controllers\Client;

use App\Enums\StatutCommission;
use App\Http\Controllers\Controller;
use App\Services\Client\ClientEarningsService;
use App\Support\Client\ClientDashboardPayloadBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardPayloadBuilder $payloadBuilder,
        private readonly ClientEarningsService $earningsService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $filters = $this->earningsService->resolveFilters($request);
        $payload = $this->payloadBuilder->build(
            $request->user(),
            $filters['date_debut'],
            $filters['date_fin'],
            $filters['vehicule_id'],
            $filters['statut']
        );

        return Inertia::render('client/Dashboard', [
            'actor' => $payload['actor'],
            'earnings' => $payload['earnings'],
            'earnings_by_vehicule' => $payload['earnings_by_vehicule'],
            'vehicules' => $payload['vehicules'],
            'status_options' => StatutCommission::options(),
            'filters' => $filters,
        ]);
    }
}
