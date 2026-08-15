<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientVehicle;
use App\Services\ImportFlotte\Normalizers\PhoneNormalizer;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD minimal des véhicules d'un client externe — jamais un `Vehicule` de flotte : pas
 * d'équipe, pas de propriétaire, pas de capacités, pas de commissions. Tous les champs métier
 * sont facultatifs (cf. ClientVehicle) : un client externe peut exister sans aucun véhicule
 * renseigné.
 */
class ClientVehicleController extends Controller
{
    use PhoneHandlerTrait;

    public function store(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $this->validated($request);

        $client->vehicules()->create([...$data, 'organization_id' => $client->organization_id]);

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule externe ajouté.');
    }

    public function update(Request $request, Client $client, ClientVehicle $vehicule): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($vehicule->client_id === $client->id, 404);

        $vehicule->update($this->validated($request));

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule externe mis à jour.');
    }

    public function destroy(Client $client, ClientVehicle $vehicule): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($vehicule->client_id === $client->id, 404);

        $vehicule->delete();

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Véhicule externe supprimé.');
    }

    /**
     * Aucun champ requis, y compris la plaque — cf. règle métier "le transport d'un client
     * externe est une information facultative, pas une condition métier". Le téléphone chauffeur suit
     * la même règle : facultatif, mais normalisé/validé selon le pays choisi (Guinée par défaut)
     * dès qu'il est renseigné.
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'nom_vehicule' => 'nullable|string|max:100',
            'immatriculation' => 'nullable|string|max:20',
            'chauffeur_nom' => 'nullable|string|max:100',
            'chauffeur_telephone' => 'nullable|string|max:20',
            'chauffeur_code_pays' => ['nullable', Rule::in(array_keys(static::supportedPays()))],
        ]);

        if (empty($data['chauffeur_telephone'])) {
            $data['chauffeur_telephone'] = null;
            $data['chauffeur_code_pays'] = null;
            $data['chauffeur_code_phone_pays'] = null;
            $data['chauffeur_pays'] = null;

            return $data;
        }

        $codePays = $data['chauffeur_code_pays'] ?? 'GN';
        $normalise = (new PhoneNormalizer)->normalize($data['chauffeur_telephone'], $codePays);

        if ($normalise['erreur']) {
            throw ValidationException::withMessages([
                'chauffeur_telephone' => 'Téléphone chauffeur invalide : '.$normalise['erreur'],
            ]);
        }

        [$pays, $codePhonePays] = static::supportedPays()[$codePays];

        $data['chauffeur_telephone'] = $normalise['telephone'];
        $data['chauffeur_code_pays'] = $codePays;
        $data['chauffeur_code_phone_pays'] = $codePhonePays;
        $data['chauffeur_pays'] = $pays;

        return $data;
    }
}
