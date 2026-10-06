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

class StoreClientController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

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
            $data['type'] ?? ClientType::EXTERNE->value,
            (bool) ($data['cashback_eligible'] ?? false),
            $data['cashback_montant_par_pack'] ?? null,
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

        ClientUniqueness::assertPhoneUniqueInOrg($data['telephone'], $orgId);

        if (! empty($data['email'])) {
            ClientUniqueness::assertEmailUniqueInOrg($data['email'], $orgId);
        }

        // Client est un rôle porté par Personne, comme Proprietaire/Fournisseur/Livreur — cf.
        // docs/identite-client-personne.md. Réutilise l'identité existante si ce téléphone
        // correspond déjà à une Personne (un autre rôle, ou un client précédemment archivé) :
        // jamais de doublon d'identité. assertPhoneUniqueInOrg() ci-dessus garantit déjà
        // qu'aucun AUTRE client actif ne porte ce téléphone, donc la Personne trouvée ici ne
        // peut jamais déjà appartenir à un client concurrent.
        $personne = Personne::resoudreOuCreer($orgId, [
            'nom_complet' => $data['nom_complet'],
            'telephone' => $data['telephone'],
            'email' => $data['email'] ?? null,
            'code_pays' => $data['code_pays'],
            'code_phone_pays' => $data['code_phone_pays'] ?? null,
            'pays' => $data['pays'] ?? null,
            'ville' => $data['ville'] ?? null,
            'adresse' => $data['adresse'] ?? null,
        ]);

        $client = Client::create([...$data, 'organization_id' => $orgId, 'personne_id' => $personne->id]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Client créé avec succès.');
    }
}
