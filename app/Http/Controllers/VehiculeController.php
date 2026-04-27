<?php

namespace App\Http\Controllers;

use App\Enums\TypeVehicule;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VehiculeFrais;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VehiculeController extends Controller
{
    private function vehiculeData(Vehicule $v): array
    {
        $equipe = $v->equipe;
        $membres = $equipe ? ($equipe->relationLoaded('membres') ? $equipe->membres : $equipe->load('membres.livreur')->membres) : collect();
        $proprietaireUser = $v->proprietaire?->user;
        $agence = $proprietaireUser
            ? (($proprietaireUser->sites->firstWhere('pivot.is_default', true)) ?? $proprietaireUser->sites->first())
            : null;

        return [
            'id' => $v->id,
            'nom_vehicule' => $v->nom_vehicule,
            'marque' => $v->marque,
            'modele' => $v->modele,
            'immatriculation' => $v->immatriculation,
            'type_vehicule' => $v->type_vehicule?->value,
            'type_label' => $v->type_label,
            'categorie' => $v->categorie,
            'capacite_packs' => $v->capacite_packs,
            'proprietaire_id' => $v->proprietaire_id,
            'proprietaire_nom' => $v->proprietaire ? trim($v->proprietaire->prenom.' '.$v->proprietaire->nom) : null,
            'proprietaire_telephone' => $v->proprietaire?->telephone,
            'proprietaire_code_phone_pays' => $v->proprietaire?->code_phone_pays,
            'agence_nom' => $agence?->nom,
            'equipe_nom' => $equipe?->nom,
            'livreur_principal_nom' => $membres->first()?->livreur
                ? trim($membres->first()->livreur->prenom.' '.$membres->first()->livreur->nom)
                : null,
            'equipe_membres' => $membres->map(fn ($m) => [
                'livreur_nom' => $m->livreur ? trim($m->livreur->prenom.' '.$m->livreur->nom) : null,
                'role' => $m->role,
                'taux_commission' => (float) $m->taux_commission,
            ])->values()->all(),
            'frais' => $v->relationLoaded('frais')
                ? $v->frais->map(fn (VehiculeFrais $f) => [
                    'id' => $f->id,
                    'montant' => (float) $f->montant,
                    'type' => $f->type,
                    'commentaire' => $f->commentaire,
                    'created_at' => $f->created_at?->format('d/m/Y H:i'),
                    'createur_nom' => $f->relationLoaded('createur') ? $f->createur?->name : null,
                ])->values()->all()
                : [],
            'frais_total' => $v->relationLoaded('frais') ? (float) $v->frais->sum('montant') : 0.0,
            'pris_en_charge_par_usine' => $v->pris_en_charge_par_usine,
            'photo_url' => $v->photo_url,
            'is_active' => $v->is_active,
        ];
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Vehicule::class);

        $vehicules = Vehicule::with(['proprietaire.user.sites', 'equipe.membres.livreur'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => $this->vehiculeData($v));

        return Inertia::render('Vehicules/Index', [
            'vehicules' => $vehicules,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Vehicule::class);

        $user = auth()->user();
        $initialProprietaireId = null;

        if ($request->filled('proprietaire_id')) {
            $initialProprietaireId = Proprietaire::query()
                ->where('organization_id', $user->organization_id)
                ->where('is_active', true)
                ->whereKey($request->string('proprietaire_id')->toString())
                ->value('id');
        }

        return Inertia::render('Vehicules/Create', [
            'proprietaires' => $this->proprietairesOptions(),
            'types' => TypeVehicule::options(),
            'initial_proprietaire_id' => $initialProprietaireId,
            'currentSiteName' => ($user->sites()->wherePivot('is_default', true)->first()
                ?? $user->sites()->first())?->nom
                ?? $user->organization?->nom
                ?? '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Vehicule::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom_vehicule' => 'required|string|max:100',
            'immatriculation' => [
                'required', 'string', 'max:20',
                Rule::unique('vehicules', 'immatriculation')->where('organization_id', $orgId),
            ],
            'type_vehicule' => ['required', Rule::in(TypeVehicule::allowedValues())],
            'categorie' => ['required', 'in:interne,externe'],
            'capacite_packs' => 'nullable|integer|min:1|max:99999',
            'proprietaire_id' => [
                Rule::requiredIf(fn () => $request->input('categorie') === 'externe'),
                'nullable',
                'string',
                Rule::exists('proprietaires', 'id')->where('organization_id', $orgId),
            ],
            'pris_en_charge_par_usine' => 'boolean',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            'is_active' => 'boolean',
        ], $this->messages());

        $data = $this->normalizeStrings($data);

        // Interne : pas de propriétaire externe
        if ($data['categorie'] === 'interne') {
            $data['proprietaire_id'] = null;
        }

        if ($request->hasFile('photo')) {
            $data['photo_path'] = (new ImageService)->storeAsWebp($request->file('photo'), 'vehicules');
        }

        unset($data['photo']);
        $vehicule = Vehicule::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('vehicules.edit', $vehicule)
            ->with('success', 'Véhicule créé avec succès.');
    }

    public function show(Vehicule $vehicule): Response
    {
        $this->authorize('view', $vehicule);

        $vehicule->load(['proprietaire', 'equipe.membres.livreur', 'frais.createur:id,name']);

        return Inertia::render('Vehicules/Show', [
            'vehicule' => $this->vehiculeData($vehicule),
        ]);
    }

    public function storeFrais(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);

        $data = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'type' => [
                'required',
                'in:carburant,reparation,autre',
            ],
            'commentaire' => [
                'nullable',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('type') === 'autre' && empty($value)) {
                        $fail('Le commentaire est obligatoire pour le type « Autre ».');
                    }
                },
            ],
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'type.required' => 'Le type est obligatoire.',
            'type.in' => 'Type de frais invalide.',
            'commentaire.max' => 'Le commentaire ne peut pas dépasser 150 caractères.',
        ]);

        if ($data['type'] !== 'autre') {
            $data['commentaire'] = null;
        }

        $vehicule->frais()->create([...$data, 'created_by' => auth()->id()]);

        return redirect()
            ->route('vehicules.show', $vehicule)
            ->with('success', 'Frais ajouté.');
    }

    public function updateFrais(Request $request, Vehicule $vehicule, VehiculeFrais $frais): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_unless($frais->vehicule_id === $vehicule->id, 403);

        $data = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'type' => [
                'required',
                'in:carburant,reparation,autre',
            ],
            'commentaire' => [
                'nullable',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('type') === 'autre' && empty($value)) {
                        $fail('Le commentaire est obligatoire pour le type « Autre ».');
                    }
                },
            ],
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'type.required' => 'Le type est obligatoire.',
            'type.in' => 'Type de frais invalide.',
            'commentaire.max' => 'Le commentaire ne peut pas dépasser 150 caractères.',
        ]);

        if ($data['type'] !== 'autre') {
            $data['commentaire'] = null;
        }

        $frais->update($data);

        return redirect()
            ->route('vehicules.show', $vehicule)
            ->with('success', 'Frais modifié.');
    }

    public function destroyFrais(Vehicule $vehicule, VehiculeFrais $frais): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_unless($frais->vehicule_id === $vehicule->id, 403);

        $frais->delete();

        return redirect()
            ->route('vehicules.show', $vehicule)
            ->with('success', 'Frais supprimé.');
    }

    public function edit(Vehicule $vehicule): Response
    {
        $this->authorize('update', $vehicule);

        $user = auth()->user();
        $vehicule->load(['proprietaire', 'equipe.membres.livreur']);

        return Inertia::render('Vehicules/Edit', [
            'vehicule' => $this->vehiculeData($vehicule),
            'proprietaires' => $this->proprietairesOptions(),
            'types' => TypeVehicule::options(),
            'currentSiteName' => ($user->sites()->wherePivot('is_default', true)->first()
                ?? $user->sites()->first())?->nom
                ?? $user->organization?->nom
                ?? '',
        ]);
    }

    public function update(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom_vehicule' => 'required|string|max:100',
            'immatriculation' => [
                'required', 'string', 'max:20',
                Rule::unique('vehicules', 'immatriculation')
                    ->where('organization_id', $orgId)
                    ->ignore($vehicule->id),
            ],
            'type_vehicule' => ['required', Rule::in(TypeVehicule::allowedValues())],
            'categorie' => ['required', 'in:interne,externe'],
            'capacite_packs' => 'nullable|integer|min:1|max:99999',
            'proprietaire_id' => [
                Rule::requiredIf(fn () => $request->input('categorie') === 'externe'),
                'nullable',
                'string',
                Rule::exists('proprietaires', 'id')->where('organization_id', $orgId),
            ],
            'pris_en_charge_par_usine' => 'boolean',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            'is_active' => 'boolean',
        ], $this->messages());

        $data = $this->normalizeStrings($data);

        if ($data['categorie'] === 'interne') {
            $data['proprietaire_id'] = null;
        }

        if ($request->hasFile('photo')) {
            $imageService = new ImageService;
            $imageService->delete($vehicule->photo_path);
            $data['photo_path'] = $imageService->storeAsWebp($request->file('photo'), 'vehicules');
        }

        unset($data['photo']);
        $vehicule->update($data);

        return redirect()->route('vehicules.edit', $vehicule)
            ->with('success', 'Véhicule mis à jour avec succès.');
    }

    public function destroy(Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('delete', $vehicule);

        if ($vehicule->photo_path) {
            Storage::disk('public')->delete($vehicule->photo_path);
        }

        // Libérer l'équipe si elle pointe vers ce véhicule
        if ($vehicule->equipe) {
            $vehicule->equipe->update(['vehicule_id' => null]);
        }
        $vehicule->delete();

        return redirect()->route('vehicules.index')
            ->with('success', 'Véhicule supprimé.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function proprietairesOptions(): array
    {
        return Proprietaire::where('organization_id', auth()->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Proprietaire $p) => [
                'value' => $p->id,
                'label' => trim("{$p->prenom} {$p->nom}"),
                'telephone' => $p->telephone,
            ])
            ->toArray();
    }

    private function normalizeStrings(array $data): array
    {
        if (! empty($data['nom_vehicule'])) {
            $data['nom_vehicule'] = mb_convert_case(mb_strtolower($data['nom_vehicule']), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['immatriculation'])) {
            $data['immatriculation'] = mb_strtoupper(trim($data['immatriculation']), 'UTF-8');
        }

        return $data;
    }

    private function messages(): array
    {
        return [
            'nom_vehicule.required' => 'Le nom du véhicule est obligatoire.',
            'immatriculation.required' => "L'immatriculation est obligatoire.",
            'immatriculation.unique' => 'Ce matricule est déjà utilisé par un autre véhicule.',
            'type_vehicule.required' => 'Le type de véhicule est obligatoire.',
            'type_vehicule.in' => 'Type de véhicule invalide.',
            'categorie.required' => 'La catégorie est obligatoire.',
            'categorie.in' => 'Catégorie invalide (interne ou externe).',
            'proprietaire_id.required' => 'Le propriétaire est obligatoire pour un véhicule externe.',
            'proprietaire_id.exists' => 'Le propriétaire sélectionné est introuvable.',
            'photo.image' => 'Le fichier doit être une image.',
            'photo.mimes' => 'La photo doit être au format jpg, jpeg, png ou webp.',
            'photo.max' => 'La photo ne peut pas dépasser 3 Mo.',
        ];
    }
}
