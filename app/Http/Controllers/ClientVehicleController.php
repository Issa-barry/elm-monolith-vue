<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD minimal des véhicules d'un partenaire — jamais un `Vehicule` de flotte : pas d'équipe,
 * pas de propriétaire, pas de capacités, pas de commissions. Tous les champs métier sont
 * facultatifs (cf. ClientVehicle) : un partenaire peut exister sans aucun véhicule renseigné.
 */
class ClientVehicleController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $this->validated($request);

        $client->vehicules()->create([...$data, 'organization_id' => $client->organization_id]);

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule partenaire ajouté.');
    }

    public function update(Request $request, Client $client, ClientVehicle $vehicule): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($vehicule->client_id === $client->id, 404);

        $vehicule->update($this->validated($request));

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule partenaire mis à jour.');
    }

    public function destroy(Client $client, ClientVehicle $vehicule): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($vehicule->client_id === $client->id, 404);

        $vehicule->delete();

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule partenaire supprimé.');
    }

    /**
     * Aucun champ requis, y compris la plaque — cf. règle métier "le transport d'un partenaire
     * est une information facultative, pas une condition métier".
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'libelle' => 'nullable|string|max:100',
            'immatriculation' => 'nullable|string|max:20',
            'chauffeur_nom' => 'nullable|string|max:100',
            'chauffeur_telephone' => 'nullable|string|max:20',
        ]);
    }
}
