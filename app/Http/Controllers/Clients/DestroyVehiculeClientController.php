<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientVehicle;
use Illuminate\Http\RedirectResponse;

class DestroyVehiculeClientController extends Controller
{
    public function __invoke(Client $client, ClientVehicle $vehicule): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($vehicule->client_id === $client->id, 404);

        $vehicule->delete();

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule externe supprimé.');
    }
}
