<?php

namespace App\Http\Controllers;

use App\Features\ModuleFeature;
use App\Models\CashbackSolde;
use App\Models\Client;
use App\Models\Organization;
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
                'cashback_eligible' => $c->cashback_eligible,
            ]);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('Clients/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'cashback_eligible' => 'boolean',
        ], $this->validationMessages());

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
                'nom' => $client->nom,
                'prenom' => $client->prenom,
                'email' => $client->email,
                'telephone' => $telephone,
                'adresse' => $client->adresse,
                'ville' => $client->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $client->is_active,
                'cashback_eligible' => $client->cashback_eligible,
            ],
            'cashback_solde' => $cashbackSolde,
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
                'nom' => $client->nom,
                'prenom' => $client->prenom,
                'email' => $client->email,
                'telephone' => $telephone,
                'adresse' => $client->adresse,
                'ville' => $client->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $client->is_active,
                'cashback_eligible' => $client->cashback_eligible,
            ],
            'cashback_solde' => $cashbackSolde,
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'cashback_eligible' => 'boolean',
        ], $this->validationMessages());

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
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
        ];
    }
}
