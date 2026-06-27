<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProprietaireController extends Controller
{
    use PhoneHandlerTrait;

    public function index(): Response
    {
        $this->authorize('viewAny', Proprietaire::class);

        $proprietaires = Proprietaire::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Proprietaire $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'prenom' => $p->prenom,
                'nom_complet' => trim("{$p->prenom} {$p->nom}"),
                'email' => $p->email,
                'telephone' => $p->telephone,
                'code_phone_pays' => $p->code_phone_pays,
                'ville' => $p->ville,
                'pays' => $p->pays,
                'code_pays' => $p->code_pays,
                'adresse' => $p->adresse,
                'is_active' => $p->is_active,
            ]);

        return Inertia::render('Proprietaires/Index', [
            'proprietaires' => $proprietaires,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Proprietaire::class);

        return Inertia::render('Proprietaires/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Proprietaire::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'required|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], $this->validationMessages());

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

        Proprietaire::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire créé avec succès.');
    }

    public function edit(Proprietaire $proprietaire): Response
    {
        $this->authorize('update', $proprietaire);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $proprietaire->telephone,
            $proprietaire->code_phone_pays,
            $proprietaire->code_pays,
            $proprietaire->pays,
        );

        return Inertia::render('Proprietaires/Edit', [
            'proprietaire' => [
                'id' => $proprietaire->id,
                'nom' => $proprietaire->nom,
                'prenom' => $proprietaire->prenom,
                'email' => $proprietaire->email,
                'telephone' => $telephone,
                'adresse' => $proprietaire->adresse,
                'ville' => $proprietaire->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $proprietaire->is_active,
            ],
        ]);
    }

    public function show(Proprietaire $proprietaire): Response
    {
        $this->authorize('view', $proprietaire);

        $vehicules = Vehicule::query()
            ->with(['typeVehicule', 'equipe.livreurs'])
            ->where('organization_id', auth()->user()->organization_id)
            ->where('proprietaire_id', $proprietaire->id)
            ->orderBy('nom_vehicule')
            ->get()
            ->map(function (Vehicule $vehicule) {
                $equipe = $vehicule->equipe;
                $chauffeur = $equipe?->livreurs->first(fn ($l) => ($l->pivot->role ?? null) === 'chauffeur');
                $convoyeurs = $equipe ? $equipe->livreurs->filter(fn ($l) => ($l->pivot->role ?? null) !== 'chauffeur') : collect();

                return [
                    'id' => $vehicule->id,
                    'nom_vehicule' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'photo_url' => $vehicule->photo_url,
                    'type_label' => $vehicule->type_label,
                    'capacite_packs' => $vehicule->capacite_packs,
                    'categorie' => $vehicule->categorie,
                    'is_active' => $vehicule->is_active,
                    'equipe_detail' => $equipe ? [
                        'nom' => $equipe->nom,
                        'taux_commission_proprietaire' => $equipe->taux_commission_proprietaire !== null
                            ? (float) $equipe->taux_commission_proprietaire
                            : null,
                        'chauffeur' => $chauffeur ? [
                            'nom' => trim($chauffeur->prenom.' '.$chauffeur->nom),
                            'telephone' => $chauffeur->telephone,
                        ] : null,
                        'convoyeurs' => $convoyeurs->map(fn ($l) => [
                            'nom' => trim($l->prenom.' '.$l->nom),
                            'telephone' => $l->telephone,
                        ])->values(),
                    ] : null,
                ];
            })
            ->values();

        $depenses = Depense::where('beneficiaire_type', 'proprietaire')
            ->where('beneficiaire_id', $proprietaire->id)
            ->where('organization_id', $proprietaire->organization_id)
            ->with('depenseType:id,libelle')
            ->orderByDesc('date_depense')
            ->get()
            ->map(fn (Depense $d) => [
                'id' => $d->id,
                'libelle' => $d->depenseType?->libelle ?? '—',
                'montant' => (float) $d->montant,
                'date_depense' => $d->date_depense?->format('d/m/Y'),
                'statut' => $d->statut,
                'commentaire' => $d->commentaire,
            ]);

        return Inertia::render('Proprietaires/Show', [
            'proprietaire' => [
                'id' => $proprietaire->id,
                'nom' => $proprietaire->nom,
                'prenom' => $proprietaire->prenom,
                'nom_complet' => trim($proprietaire->prenom.' '.$proprietaire->nom),
                'email' => $proprietaire->email,
                'telephone' => $proprietaire->telephone,
                'code_phone_pays' => $proprietaire->code_phone_pays,
                'ville' => $proprietaire->ville,
                'pays' => $proprietaire->pays,
                'code_pays' => $proprietaire->code_pays,
                'adresse' => $proprietaire->adresse,
                'is_active' => $proprietaire->is_active,
                'vehicules_count' => $vehicules->count(),
            ],
            'vehicules' => $vehicules,
            'depenses' => $depenses,
            'can_create_vehicule' => auth()->user()->can('vehicules.create'),
        ]);
    }

    public function update(Request $request, Proprietaire $proprietaire): RedirectResponse
    {
        $this->authorize('update', $proprietaire);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'required|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], $this->validationMessages());

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength($data);
        $data = $this->normalizePersonData($data);

        if (! empty($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        $this->assertPhoneUniqueInOrg($data['telephone'], $proprietaire->organization_id, $proprietaire->id);

        if (! empty($data['email'])) {
            $this->assertEmailUniqueInOrg($data['email'], $proprietaire->organization_id, $proprietaire->id);
        }

        $proprietaire->update($data);

        return redirect()->route('proprietaires.edit', $proprietaire)
            ->with('success', 'Propriétaire mis à jour avec succès.');
    }

    public function destroy(Proprietaire $proprietaire): RedirectResponse
    {
        $this->authorize('delete', $proprietaire);
        $proprietaire->delete();

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire supprimé.');
    }

    private function assertPhoneUniqueInOrg(string $phone, string $orgId, ?string $ignoreId = null): void
    {
        $exists = Proprietaire::where('organization_id', $orgId)
            ->where('telephone', $phone)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro de téléphone est déjà utilisé par un autre propriétaire.',
            ]);
        }
    }

    private function assertEmailUniqueInOrg(string $email, string $orgId, ?string $ignoreId = null): void
    {
        $exists = Proprietaire::where('organization_id', $orgId)
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => 'Cet email est déjà utilisé par un autre propriétaire.',
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
            'ville.required' => 'La ville est obligatoire.',
        ];
    }
}
