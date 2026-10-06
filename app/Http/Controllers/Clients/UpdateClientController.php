<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Personne;
use App\Services\CashbackEligibiliteService;
use App\Support\Clients\ClientUniqueness;
use App\Support\Clients\ClientValidationMessages;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateClientController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'nom_complet' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'type' => ['nullable', Rule::in(ClientType::values())],
            'cashback_eligible' => 'boolean',
            'cashback_montant_par_pack' => 'nullable|integer|min:1',
        ], ClientValidationMessages::pour());

        $data = CashbackEligibiliteService::resoudreEligibilite($data);
        CashbackEligibiliteService::validerCoherence(
            $data['type'] ?? $client->type->value,
            (bool) ($data['cashback_eligible'] ?? $client->cashback_eligible),
            $data['cashback_montant_par_pack'] ?? $client->cashback_montant_par_pack,
        );

        // Règle métier : Guinée → Conakry par défaut
        if (empty($data['ville']) && ($data['code_pays'] ?? null) === 'GN') {
            $data['ville'] = 'Conakry';
        }

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength($data);
        $data = $this->normalizePersonData($data);

        if (! empty($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        ClientUniqueness::assertPhoneUniqueInOrg($data['telephone'], $client->organization_id, $client->id);

        if (! empty($data['email'])) {
            ClientUniqueness::assertEmailUniqueInOrg($data['email'], $client->organization_id, $client->id);
        }

        $identitePersonne = [
            'nom_complet' => $data['nom_complet'],
            'telephone' => $data['telephone'],
            'email' => $data['email'] ?? null,
            'code_pays' => $data['code_pays'],
            'code_phone_pays' => $data['code_phone_pays'] ?? null,
            'pays' => $data['pays'] ?? null,
            'ville' => $data['ville'] ?? null,
            'adresse' => $data['adresse'] ?? null,
        ];

        if ($client->personne_id) {
            // Édite l'identité déjà rattachée EN PLACE, jamais de re-résolution par téléphone
            // (même principe que ProprietaireController::update()) : éditer un client ne doit
            // jamais le rattacher silencieusement à une autre Personne existante. La garde
            // ci-dessous protège la contrainte unique (organization_id, telephone_normalise) —
            // sans elle, un nouveau téléphone déjà porté par une AUTRE Personne (un autre rôle)
            // heurterait une exception SQL brute au lieu d'un message de validation clair.
            Personne::assertTelephoneDisponible($client->organization_id, $data['telephone'], $client->personne_id);
            $client->personne->update([
                ...$identitePersonne,
                'telephone_normalise' => Personne::normaliserTelephone($data['telephone']),
            ]);
        } else {
            // Filet de sécurité : ne devrait plus se produire une fois le backfill exécuté,
            // mais garantit qu'aucun client ne reste sans Personne après une modification.
            $data['personne_id'] = Personne::resoudreOuCreer($client->organization_id, $identitePersonne)->id;
        }

        $client->update($data);

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Client mis à jour avec succès.');
    }
}
