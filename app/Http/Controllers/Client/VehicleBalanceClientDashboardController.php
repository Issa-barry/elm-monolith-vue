<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Client\Data\VehiculeEarningsRow;
use App\Support\Client\ClientDashboardPayloadBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehicleBalanceClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardPayloadBuilder $payloadBuilder,
    ) {}

    public function __invoke(Request $request, string $vehiculeId): Response
    {
        $dateDebut = $request->input('date_debut') ?: null;
        $dateFin = $request->input('date_fin') ?: null;
        $payload = $this->payloadBuilder->build(
            $request->user(),
            $dateDebut,
            $dateFin,
            $vehiculeId
        );

        $vehicule = collect($payload['vehicules'])
            ->first(fn (array $item) => (string) $item['id'] === $vehiculeId);

        if ($vehicule === null) {
            abort(404);
        }

        $summary = collect($payload['earnings_by_vehicule'])
            ->first(fn (VehiculeEarningsRow $item) => $item->vehiculeId === $vehiculeId);

        if ($summary === null) {
            $summary = new VehiculeEarningsRow(
                vehiculeId: $vehicule['id'],
                nomVehicule: $vehicule['nom_vehicule'],
                immatriculation: $vehicule['immatriculation'],
                fraisDepenses: 0.0,
                totalEarned: 0.0,
                totalPaid: 0.0,
                balance: 0.0,
            );
        }

        return Inertia::render('client/VehicleBalanceDetail', [
            'vehicule' => $vehicule,
            'summary' => $summary,
            'statement' => $payload['statement'],
            'filters' => ['date_debut' => $dateDebut, 'date_fin' => $dateFin],
        ]);
    }
}
