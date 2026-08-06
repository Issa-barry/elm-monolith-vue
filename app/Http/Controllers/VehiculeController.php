<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\EquipeLivreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\VehiculeFrais;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VehiculeController extends Controller
{
    private function vehiculeData(Vehicule $v): array
    {
        $equipe = $v->equipe;
        $membres = $equipe ? $equipe->loadMissing('membres.livreur')->membres : collect();
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
            'type_vehicule_id' => $v->type_vehicule_id,
            'type_label' => $v->type_label,
            'categorie' => $v->categorie,
            // Capacité propre au véhicule si définie (override), sinon celle
            // par défaut de son type — c'est cette dernière qui est utilisée
            // en pratique tant qu'aucune capacité spécifique n'a été saisie
            // (import flotte notamment : capacite_packs n'est jamais renseigné).
            'capacite_packs' => $v->capacite_packs ?? $v->typeVehicule?->capacite_defaut,
            'site_id' => $v->site_id,
            'site_nom' => $v->relationLoaded('site') ? $v->site?->nom : null,
            'proprietaire_id' => $v->proprietaire_id,
            'proprietaire_nom' => $v->proprietaire ? trim($v->proprietaire->prenom.' '.$v->proprietaire->nom) : null,
            'proprietaire_telephone' => $v->proprietaire?->telephone,
            'proprietaire_code_phone_pays' => $v->proprietaire?->code_phone_pays,
            'agence_nom' => $agence?->nom,
            'equipe_id' => $equipe?->id,
            'equipe_nom' => $equipe ? $v->nom_vehicule : null,
            'livreur_principal_nom' => $membres->first()
                ? $this->membreLabel($membres->first())
                : null,
            // Jamais de prenom/nom affiché côté Eau La Maman : le libellé
            // (nom_complet, ou "Chauffeur-1" en filet de sécurité pour les
            // données historiques sans nom_complet) est déjà composé ici pour
            // ne pas dupliquer cette logique côté frontend — voir self::membreLabel().
            'equipe_membres' => $this->membresAvecLabel($membres)->map(fn ($m) => [
                'livreur_nom' => $m['label'],
                'telephone' => $m['membre']->livreur?->telephone ?? null,
                'role' => $m['membre']->role,
                'taux_commission' => (float) $m['membre']->taux_commission,
                'montant_par_pack' => (int) $m['membre']->montant_par_pack,
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
            'commission_eligible' => $v->commission_eligible,
            'photo_url' => $v->photo_url,
            'is_active' => $v->is_active,
        ];
    }

    /**
     * Libellé = nom_complet du livreur — jamais construit depuis prenom/nom,
     * qui ne sont jamais affichés côté Eau La Maman. nom_complet est désormais
     * toujours renseigné à la création d'un livreur (repli automatique sur une
     * désignation "Chauffeur-1 {véhicule}", cf. Livreur::designationParDefaut()),
     * donc PAS de préfixe "Chauffeur-1 — " ajouté ici : ça doublonnerait ce que
     * nom_complet porte déjà. Le calcul d'ordinal ne sert plus que de filet de
     * sécurité pour les données historiques créées avant ce repli automatique
     * (nom_complet encore vide en base).
     *
     * @return Collection<int, array{membre: EquipeLivreur, label: string}>
     */
    private function membresAvecLabel(Collection $membres): Collection
    {
        $roleCounts = [];

        return $membres->map(function (EquipeLivreur $m) use (&$roleCounts) {
            $nomComplet = trim((string) ($m->livreur?->nom_complet ?? ''));

            if ($nomComplet !== '') {
                return ['membre' => $m, 'label' => $nomComplet];
            }

            $roleCounts[$m->role] = ($roleCounts[$m->role] ?? 0) + 1;
            $ordinal = ($m->role === 'chauffeur' ? 'Chauffeur-' : 'Convoyeur-').$roleCounts[$m->role];

            return ['membre' => $m, 'label' => $ordinal];
        });
    }

    private function membreLabel(EquipeLivreur $m): string
    {
        return $this->membresAvecLabel(collect([$m]))->first()['label'];
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Vehicule::class);

        $vehicules = Vehicule::with(['typeVehicule', 'site', 'proprietaire.user.sites', 'equipe.membres.livreur'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => $this->vehiculeData($v));

        return Inertia::render('Vehicules/Index', [
            'vehicules' => $vehicules,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $this->authorize('create', Vehicule::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $initialProprietaireId = null;

        if ($request->filled('proprietaire_id')) {
            $initialProprietaireId = Proprietaire::query()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->whereKey($request->string('proprietaire_id')->toString())
                ->value('id');
        }

        $canChangeSite = $user->isAdmin();
        $defaultSiteId = $this->defaultSiteId();

        // Un admin arrivant depuis une page de site (ex: ?site_id=... depuis
        // Sites/Show) voit ce site pré-sélectionné au lieu de son propre site
        // par défaut. Un non-admin ne peut de toute façon choisir que son site
        // (verrouillé côté formulaire), donc ce contexte ne s'applique pas à lui.
        if ($canChangeSite && $request->filled('site_id')) {
            $contextSiteId = Site::query()
                ->where('organization_id', $orgId)
                ->whereKey($request->string('site_id')->toString())
                ->value('id');
            if ($contextSiteId) {
                $defaultSiteId = $contextSiteId;
            }
        }

        return Inertia::render('Vehicules/Create', [
            'proprietaires' => $this->proprietairesOptions(),
            'types' => $this->typesOptions(),
            'initial_proprietaire_id' => $initialProprietaireId,
            'sites' => $this->sitesOptions($user, $orgId),
            'default_site_id' => $defaultSiteId,
            'can_change_site' => $canChangeSite,
            'default_proprietaire_id' => $this->defaultProprietaireInterneId($orgId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Vehicule::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom_vehicule' => 'required|string|max:100',
            'immatriculation' => [
                'required', 'string', 'max:20',
                Rule::unique('vehicules', 'immatriculation')->where('organization_id', $orgId),
            ],
            'type_vehicule_id' => [
                'required', 'string',
                Rule::exists('type_vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'categorie' => ['required', 'in:interne,externe'],
            'capacite_packs' => 'nullable|integer|min:1|max:99999',
            // Chaque véhicule (interne ou externe) est rattaché à un site.
            'site_id' => [
                'required', 'string',
                Rule::exists('sites', 'id')->where('organization_id', $orgId),
            ],
            'proprietaire_id' => [
                Rule::requiredIf(fn () => $request->input('categorie') === 'externe'),
                'nullable',
                'string',
                Rule::exists('proprietaires', 'id')->where('organization_id', $orgId),
            ],
            'pris_en_charge_par_usine' => 'required|boolean',
            'commission_eligible' => 'required|boolean',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            'is_active' => 'boolean',
        ], $this->messages());

        // Non-admin : ne peut créer que sur son(ses) propre(s) site(s) — un
        // admin peut choisir n'importe quel site de l'organisation.
        if (! $user->isAdmin()) {
            $allowedSiteIds = $user->sites()->pluck('sites.id')->all();
            abort_unless(
                in_array($data['site_id'], $allowedSiteIds, true),
                403,
                'Vous ne pouvez créer un véhicule que pour votre site.'
            );
        }

        $data = $this->normalizeStrings($data);

        // Interne : prise en charge obligatoire, propriétaire par défaut
        // (Moussa SIDIBE) si non précisé explicitement.
        if ($data['categorie'] === 'interne') {
            $data['proprietaire_id'] ??= $this->defaultProprietaireInterneId($orgId);
            $data['pris_en_charge_par_usine'] = true;
        }

        if ($request->hasFile('photo')) {
            $data['photo_path'] = (new ImageService)->storeAsWebp($request->file('photo'), 'vehicules');
        }

        unset($data['photo']);
        $data['is_active'] = false; // inactif jusqu'à attribution d'une équipe
        $vehicule = Vehicule::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('vehicules.show', $vehicule)
            ->with('success', 'Véhicule créé avec succès.');
    }

    public function show(Vehicule $vehicule): Response
    {
        $this->authorize('view', $vehicule);

        $vehicule->load(['typeVehicule', 'site', 'proprietaire', 'equipe.membres.livreur', 'equipe.proprietaire']);

        $depenses = Depense::where('beneficiaire_type', 'vehicule')
            ->where('beneficiaire_id', $vehicule->id)
            ->where('organization_id', $vehicule->organization_id)
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

        $equipe = $vehicule->equipe;
        $equipeData = null;
        if ($equipe) {
            $roleCounts = [];
            $membres = $equipe->membres->sortBy('ordre')->map(function ($m) use (&$roleCounts) {
                $role = $m->role;
                $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;

                return [
                    'livreur_id' => $m->livreur_id,
                    'nom_complet' => $m->livreur?->nom_complet,
                    'telephone' => $m->livreur?->telephone ?? '',
                    'role' => $role,
                    'montant_par_pack' => (float) $m->montant_par_pack,
                    'taux_commission' => (float) $m->taux_commission,
                    'ordre' => $m->ordre,
                    'numero' => $roleCounts[$role],
                ];
            })->values()->all();

            $equipeData = [
                'id' => $equipe->id,
                'is_active' => $equipe->is_active,
                'commission_unitaire_par_pack' => (float) $equipe->commission_unitaire_par_pack,
                'montant_par_pack_proprietaire' => $equipe->montant_par_pack_proprietaire !== null ? (float) $equipe->montant_par_pack_proprietaire : null,
                'taux_commission_proprietaire' => $equipe->taux_commission_proprietaire !== null ? (float) $equipe->taux_commission_proprietaire : null,
                'proprietaire_id' => $equipe->proprietaire_id,
                'proprietaire_nom' => $equipe->proprietaire ? trim("{$equipe->proprietaire->prenom} {$equipe->proprietaire->nom}") : null,
                'membres' => $membres,
            ];
        }

        return Inertia::render('Vehicules/Show', [
            'vehicule' => $this->vehiculeData($vehicule),
            'depenses' => $depenses,
            'equipe' => $equipeData,
            'proprietaires' => $this->proprietairesOptions(),
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
            'type.in' => 'Type de dépense invalide.',
            'commentaire.max' => 'Le commentaire ne peut pas dépasser 150 caractères.',
        ]);

        if ($data['type'] !== 'autre') {
            $data['commentaire'] = null;
        }

        $vehicule->frais()->create([...$data, 'created_by' => auth()->id()]);

        return redirect()
            ->route('vehicules.show', $vehicule)
            ->with('success', 'Dépense ajoutée.');
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
            'type.in' => 'Type de dépense invalide.',
            'commentaire.max' => 'Le commentaire ne peut pas dépasser 150 caractères.',
        ]);

        if ($data['type'] !== 'autre') {
            $data['commentaire'] = null;
        }

        $frais->update($data);

        return redirect()
            ->route('vehicules.show', $vehicule)
            ->with('success', 'Dépense modifiée.');
    }

    public function destroyFrais(Vehicule $vehicule, VehiculeFrais $frais): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_unless($frais->vehicule_id === $vehicule->id, 403);

        $frais->delete();

        return redirect()
            ->route('vehicules.show', $vehicule)
            ->with('success', 'Dépense supprimée.');
    }

    public function edit(Vehicule $vehicule): Response
    {
        $this->authorize('update', $vehicule);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $vehicule->load(['typeVehicule', 'site', 'proprietaire', 'equipe.membres.livreur']);

        return Inertia::render('Vehicules/Edit', [
            'vehicule' => $this->vehiculeData($vehicule),
            'proprietaires' => $this->proprietairesOptions(),
            'types' => $this->typesOptions(),
            'sites' => $this->sitesOptions($user, $orgId),
            'can_change_site' => $user->isAdmin(),
            'default_proprietaire_id' => $this->defaultProprietaireInterneId($orgId),
        ]);
    }

    public function update(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $data = $request->validate([
            'nom_vehicule' => 'required|string|max:100',
            'immatriculation' => [
                'required', 'string', 'max:20',
                Rule::unique('vehicules', 'immatriculation')
                    ->where('organization_id', $orgId)
                    ->ignore($vehicule->id),
            ],
            'type_vehicule_id' => [
                'required', 'string',
                Rule::exists('type_vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'categorie' => ['required', 'in:interne,externe'],
            'capacite_packs' => 'nullable|integer|min:1|max:99999',
            // Chaque véhicule (interne ou externe) est rattaché à un site.
            'site_id' => [
                'required', 'string',
                Rule::exists('sites', 'id')->where('organization_id', $orgId),
            ],
            'proprietaire_id' => [
                Rule::requiredIf(fn () => $request->input('categorie') === 'externe'),
                'nullable',
                'string',
                Rule::exists('proprietaires', 'id')->where('organization_id', $orgId),
            ],
            'pris_en_charge_par_usine' => 'required|boolean',
            'commission_eligible' => 'required|boolean',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            'is_active' => 'boolean',
        ], $this->messages());

        // Non-admin : ne peut affecter le véhicule qu'à son(ses) propre(s)
        // site(s) — un admin peut choisir n'importe quel site de l'organisation.
        if (! $user->isAdmin()) {
            $allowedSiteIds = $user->sites()->pluck('sites.id')->all();
            abort_unless(
                in_array($data['site_id'], $allowedSiteIds, true),
                403,
                'Vous ne pouvez pas choisir ce site.'
            );
        }

        $data = $this->normalizeStrings($data);

        if ($data['categorie'] === 'interne') {
            $data['proprietaire_id'] ??= $this->defaultProprietaireInterneId($orgId);
            $data['pris_en_charge_par_usine'] = true;
        }

        if ($request->hasFile('photo')) {
            $imageService = new ImageService;
            $imageService->delete($vehicule->photo_path);
            $data['photo_path'] = $imageService->storeAsWebp($request->file('photo'), 'vehicules');
        }

        unset($data['photo']);
        $vehicule->update($data);

        // Un véhicule sans équipe ne peut pas être actif
        $vehicule->load('equipe');
        if (! $vehicule->equipe) {
            $vehicule->update(['is_active' => false]);
        }

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

    private function typesOptions(): array
    {
        return TypeVehicule::where('organization_id', auth()->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (TypeVehicule $t) => [
                'value' => $t->id,
                'label' => $t->nom,
                'capacite_defaut' => $t->capacite_defaut,
            ])
            ->toArray();
    }

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

    /**
     * Sites sélectionnables pour le formulaire véhicule : un admin voit tous
     * les sites de l'organisation (peut créer/affecter pour n'importe lequel),
     * un non-admin ne voit que le ou les sites auxquels il est rattaché — voir
     * self::store()/self::update() pour l'application côté serveur de cette règle.
     *
     * @return array<int, array{id: string, nom: string}>
     */
    private function sitesOptions(User $user, string $orgId): array
    {
        $sites = $user->isAdmin()
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->where('sites.organization_id', $orgId)->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])->values()->all();
    }

    private function defaultSiteId(): ?string
    {
        return auth()->user()->sites()
            ->wherePivot('is_default', true)
            ->select('sites.id')
            ->first()?->id;
    }

    /**
     * Propriétaire par défaut des véhicules "interne" (propriété de
     * l'organisation) — voir database/seeders/ProprietairesSeeder.php.
     */
    private function defaultProprietaireInterneId(string $orgId): ?string
    {
        return Proprietaire::where('organization_id', $orgId)
            ->where('telephone', '+224622602693')
            ->value('id');
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
            'type_vehicule_id.required' => 'Le type de véhicule est obligatoire.',
            'type_vehicule_id.exists' => 'Type de véhicule invalide.',
            'categorie.required' => 'La catégorie est obligatoire.',
            'categorie.in' => 'Catégorie invalide (interne ou externe).',
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Le site sélectionné est introuvable.',
            'proprietaire_id.required' => 'Le propriétaire est obligatoire pour un véhicule externe.',
            'proprietaire_id.exists' => 'Le propriétaire sélectionné est introuvable.',
            'photo.image' => 'Le fichier doit être une image.',
            'photo.mimes' => 'La photo doit être au format jpg, jpeg, png ou webp.',
            'photo.max' => 'La photo ne peut pas dépasser 3 Mo.',
            'pris_en_charge_par_usine.required' => 'Veuillez indiquer si les dépenses sont prises en charge par l\'usine (Oui ou Non).',
            'commission_eligible.required' => 'Veuillez indiquer si ce véhicule est éligible aux commissions (Oui ou Non).',
        ];
    }
}
