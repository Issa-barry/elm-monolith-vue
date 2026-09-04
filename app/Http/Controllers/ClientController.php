<?php

namespace App\Http\Controllers;

use App\Enums\ClientType;
use App\Features\ModuleFeature;
use App\Models\CashbackSolde;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Parametre;
use App\Services\CashbackEligibiliteService;
use App\Services\DerogationImpayesService;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class ClientController extends Controller
{
    use PhoneHandlerTrait;

    public function index(): Response
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'prenom' => $c->prenom,
                'nom_complet' => $c->nom_complet,
                'email' => $c->email,
                'telephone' => $c->telephone,
                'code_phone_pays' => $c->code_phone_pays,
                'ville' => $c->ville,
                'pays' => $c->pays,
                'code_pays' => $c->code_pays,
                'adresse' => $c->adresse,
                'is_active' => $c->is_active,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'cashback_eligible' => $c->cashback_eligible,
                'cashback_montant_par_pack' => $c->cashback_montant_par_pack,
            ]);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'types' => ClientType::options(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('Clients/Create', [
            'types' => ClientType::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
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
        ], $this->validationMessages());

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

        $this->assertPhoneUniqueInOrg($data['telephone'], $orgId);

        if (! empty($data['email'])) {
            $this->assertEmailUniqueInOrg($data['email'], $orgId);
        }

        $client = Client::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Client créé avec succès.');
    }

    public function show(Client $client): Response
    {
        $this->authorize('view', $client);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $client->telephone,
            $client->code_phone_pays,
            $client->code_pays,
            $client->pays,
        );

        // Widget cashback (affiché uniquement si le module est actif)
        $cashbackSolde = null;
        $org = auth()->user()->organization_id
            ? Organization::find(auth()->user()->organization_id)
            : null;

        if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
            $solde = CashbackSolde::where('organization_id', $client->organization_id)
                ->where('client_id', $client->id)
                ->first();

            $cashbackSolde = $solde ? [
                'cumul_achats' => $solde->cumul_achats,
                'cashback_en_attente' => $solde->cashback_en_attente,
                'total_cashback_gagne' => $solde->total_cashback_gagne,
                'total_cashback_verse' => $solde->total_cashback_verse,
            ] : [
                'cumul_achats' => 0,
                'cashback_en_attente' => 0,
                'total_cashback_gagne' => 0,
                'total_cashback_verse' => 0,
            ];
        }

        return Inertia::render('Clients/Show', [
            'client' => [
                'id' => $client->id,
                'nom_complet' => $client->nom_complet,
                'email' => $client->email,
                'telephone' => $telephone,
                'adresse' => $client->adresse,
                'ville' => $client->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $client->is_active,
                'type' => $client->type->value,
                'type_label' => $client->type->label(),
                'cashback_eligible' => $client->cashback_eligible,
                'cashback_montant_par_pack' => $client->cashback_montant_par_pack,
                'derogation_impayes_autorisee' => $client->derogation_impayes_autorisee,
                'seuil_derogation_impayes' => $client->seuil_derogation_impayes,
            ],
            'types' => ClientType::options(),
            'cashback_solde' => $cashbackSolde,
            'seuil_global_impayes' => Parametre::getVentesSeuilImpayesMax($client->organization_id),
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorize('update', $client);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $client->telephone,
            $client->code_phone_pays,
            $client->code_pays,
            $client->pays,
        );

        // Widget cashback (affiché uniquement si le module est actif)
        $cashbackSolde = null;
        $org = auth()->user()->organization_id
            ? Organization::find(auth()->user()->organization_id)
            : null;

        if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
            $solde = CashbackSolde::where('organization_id', $client->organization_id)
                ->where('client_id', $client->id)
                ->first();

            $cashbackSolde = $solde ? [
                'cumul_achats' => $solde->cumul_achats,
                'cashback_en_attente' => $solde->cashback_en_attente,
                'total_cashback_gagne' => $solde->total_cashback_gagne,
                'total_cashback_verse' => $solde->total_cashback_verse,
            ] : [
                'cumul_achats' => 0,
                'cashback_en_attente' => 0,
                'total_cashback_gagne' => 0,
                'total_cashback_verse' => 0,
            ];
        }

        return Inertia::render('Clients/Edit', [
            'client' => [
                'id' => $client->id,
                'nom_complet' => $client->nom_complet,
                'email' => $client->email,
                'telephone' => $telephone,
                'adresse' => $client->adresse,
                'ville' => $client->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $client->is_active,
                'type' => $client->type->value,
                'type_label' => $client->type->label(),
                'cashback_eligible' => $client->cashback_eligible,
                'cashback_montant_par_pack' => $client->cashback_montant_par_pack,
                'derogation_impayes_autorisee' => $client->derogation_impayes_autorisee,
                'seuil_derogation_impayes' => $client->seuil_derogation_impayes,
            ],
            'types' => ClientType::options(),
            'seuil_global_impayes' => Parametre::getVentesSeuilImpayesMax($client->organization_id),
            'vehicules' => $client->vehicules()->get(['id', 'nom_vehicule', 'immatriculation', 'chauffeur_nom', 'chauffeur_telephone', 'chauffeur_code_pays'])
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'chauffeur_nom' => $v->chauffeur_nom,
                    'chauffeur_telephone' => $v->chauffeur_telephone,
                    'chauffeur_code_pays' => $v->chauffeur_code_pays,
                ]),
            'cashback_solde' => $cashbackSolde,
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
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
        ], $this->validationMessages());

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

        $this->assertPhoneUniqueInOrg($data['telephone'], $client->organization_id, $client->id);

        if (! empty($data['email'])) {
            $this->assertEmailUniqueInOrg($data['email'], $client->organization_id, $client->id);
        }

        $client->update($data);

        return redirect()->route('clients.edit', $client)
            ->with('success', 'Client mis à jour avec succès.');
    }

    /**
     * Met à jour uniquement la configuration cashback depuis la fiche client, sans réémettre
     * les autres informations du client. La même règle métier que le formulaire complet reste
     * appliquée : un Revendeur ne peut pas être désactivé et tout cashback actif exige un
     * montant par pack strictement positif.
     */
    public function updateCashback(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'cashback_eligible' => 'required|boolean',
            'cashback_montant_par_pack' => 'nullable|integer|min:1',
        ]);

        CashbackEligibiliteService::validerCoherence(
            $client->type->value,
            (bool) $data['cashback_eligible'],
            $data['cashback_montant_par_pack'] ?? null,
        );

        $client->update([
            'cashback_eligible' => $data['cashback_eligible'],
            'cashback_montant_par_pack' => $data['cashback_montant_par_pack'] ?? null,
        ]);

        return back()->with('success', 'Configuration cashback mise à jour.');
    }

    /**
     * Active/désactive la dérogation ET son plafond, atomiquement, directement depuis la fiche
     * client (Clients/Show.vue) — même schéma que VehiculeController::updateDerogation(), même
     * règle de cohérence (DerogationImpayesService, mutualisée). `seuil_derogation_impayes` est
     * facultatif dans la requête : omis, le plafond déjà enregistré en base est conservé tel
     * quel (ex: réactiver une dérogation précédemment désactivée sans ressaisir son montant).
     */
    public function updateDerogation(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'derogation_impayes_autorisee' => 'required|boolean',
            'seuil_derogation_impayes' => 'nullable|integer|min:0|max:999999999',
        ]);

        $seuil = array_key_exists('seuil_derogation_impayes', $data) && $request->filled('seuil_derogation_impayes')
            ? $data['seuil_derogation_impayes']
            : $client->seuil_derogation_impayes;

        DerogationImpayesService::validerCoherence(
            $data['derogation_impayes_autorisee'],
            $seuil,
            $client->organization_id,
            'ce client',
        );

        $client->update([
            'derogation_impayes_autorisee' => $data['derogation_impayes_autorisee'],
            'seuil_derogation_impayes' => $seuil,
        ]);

        $label = $data['derogation_impayes_autorisee'] ? 'activée' : 'désactivée';

        return back()->with('success', "Dérogation impayés {$label}.");
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);
        $client->delete();

        return redirect()->route('clients.index')
            ->with('success', 'Client supprimé.');
    }

    private function assertPhoneUniqueInOrg(string $phone, string $orgId, ?string $ignoreId = null): void
    {
        $exists = Client::where('organization_id', $orgId)
            ->where('telephone', $phone)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro de téléphone est déjà utilisé par un autre client.',
            ]);
        }
    }

    private function assertEmailUniqueInOrg(string $email, string $orgId, ?string $ignoreId = null): void
    {
        $exists = Client::where('organization_id', $orgId)
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => 'Cet email est déjà utilisé par un autre client.',
            ]);
        }
    }

    private function validationMessages(): array
    {
        return [
            'nom_complet.required' => 'Le nom complet est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
        ];
    }
}
