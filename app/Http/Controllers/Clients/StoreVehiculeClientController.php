<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Clients\ClientVehicleData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreVehiculeClientController extends Controller
{
    public function __invoke(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = ClientVehicleData::validated($request);

        $client->vehicules()->create([...$data, 'organization_id' => $client->organization_id]);

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule externe ajouté.');
    }
}
