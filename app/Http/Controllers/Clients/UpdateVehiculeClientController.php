<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientVehicle;
use App\Support\Clients\ClientVehicleData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateVehiculeClientController extends Controller
{
    public function __invoke(Request $request, Client $client, ClientVehicle $vehicule): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($vehicule->client_id === $client->id, 404);

        $vehicule->update(ClientVehicleData::validated($request));

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule externe mis à jour.');
    }
}
