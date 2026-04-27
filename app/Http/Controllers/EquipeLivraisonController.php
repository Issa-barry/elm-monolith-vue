<?php

namespace App\Http\Controllers;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EquipeLivraisonController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', EquipeLivraison::class);

        $equipes = EquipeLivraison::with('membres.livreur', 'proprietaire', 'vehicule')
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (EquipeLivraison $e) => $this->equipeData($e));

        return Inertia::render('EquipesLivraison/Index', [
            'equipes' => $equipes,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', EquipeLivraison::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('EquipesLivraison/Create', [
            'proprietaires' => $this->proprietairesOptions($orgId),
            'tauxProprietaireDefaut' => Parametre::getTauxProprietaireDefaut($orgId),
            'vehicules' => $this->vehiculesOptions($orgId),
            'currentSiteName' => $this->currentSiteName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EquipeLivraison::class);

        $orgId = auth()->user()->organization_id;
        $vehiculeSelectionne = $this->selectedVehicule($orgId, $request);
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100', Rule::unique('equipes_livraison', 'nom')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'is_active' => 'boolean',
            'vehicule_id' => [
                'required', 'string',
                Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                Rule::unique('equipes_livraison', 'vehicule_id')->whereNull('deleted_at'),
            ],
            'proprietaire_id' => [
                Rule::requiredIf(fn () => $this->isVehiculeExterne($vehiculeSelectionne)),
                'nullable',
                'string',
                Rule::exists('proprietaires', 'id')->where('organization_id', $orgId),
            ],
            'taux_commission_proprietaire' => 'required|numeric|min:0|max:100',
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            'membres.*.nom' => 'required|string|max:255',
            'membres.*.prenom' => 'required|string|max:255',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            'membres.*.taux_commission' => 'required|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        if ($vehiculeSelectionne?->categorie === 'interne') {
            $data['proprietaire_id'] = null;
        }

        $this->validateUniquePhones($data['membres']);
        $this->validateTotalTaux($data['membres'], (float) $data['taux_commission_proprietaire']);
        $this->validateMembresExclusivite($data['membres'], $orgId);

        DB::transaction(function () use ($data, $orgId) {
            $equipe = EquipeLivraison::create([
                'organization_id' => $orgId,
                'vehicule_id' => $data['vehicule_id'],
                'proprietaire_id' => $data['proprietaire_id'],
                'nom' => $data['nom'],
                'is_active' => $data['is_active'] ?? true,
                'taux_commission_proprietaire' => $data['taux_commission_proprietaire'],
            ]);

            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId);
                EquipeLivreur::create([
                    'equipe_id' => $equipe->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'taux_commission' => $m['taux_commission'],
                    'ordre' => $m['ordre'] ?? $index,
                ]);
            }
        });

        return redirect()->route('equipes-livraison.index')
            ->with('success', 'Équipe créée avec succès.');
    }

    public function edit(EquipeLivraison $equipes_livraison): Response
    {
        $this->authorize('update', $equipes_livraison);

        $orgId = auth()->user()->organization_id;
        $equipes_livraison->load('membres.livreur', 'proprietaire', 'vehicule');

        return Inertia::render('EquipesLivraison/Edit', [
            'equipe' => $this->equipeData($equipes_livraison),
            'proprietaires' => $this->proprietairesOptions($orgId),
            'tauxProprietaireDefaut' => Parametre::getTauxProprietaireDefaut($orgId),
            'vehicules' => $this->vehiculesOptions($orgId, $equipes_livraison->id),
            'currentSiteName' => $this->currentSiteName(),
        ]);
    }

    public function show(EquipeLivraison $equipes_livraison): Response
    {
        $this->authorize('view', $equipes_livraison);

        $equipes_livraison->load('membres.livreur', 'proprietaire', 'vehicule');

        return Inertia::render('EquipesLivraison/Show', [
            'equipe' => $this->equipeData($equipes_livraison),
        ]);
    }

    public function update(Request $request, EquipeLivraison $equipes_livraison): RedirectResponse
    {
        $this->authorize('update', $equipes_livraison);

        $orgId = auth()->user()->organization_id;
        $vehiculeSelectionne = $this->selectedVehicule($orgId, $request);

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100', Rule::unique('equipes_livraison', 'nom')->where('organization_id', $orgId)->whereNull('deleted_at')->ignore($equipes_livraison->id)],
            'is_active' => 'boolean',
            'vehicule_id' => [
                'required', 'string',
                Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                Rule::unique('equipes_livraison', 'vehicule_id')->whereNull('deleted_at')->ignore($equipes_livraison->id),
            ],
            'proprietaire_id' => [
                Rule::requiredIf(fn () => $this->isVehiculeExterne($vehiculeSelectionne)),
                'nullable',
                'string',
                Rule::exists('proprietaires', 'id')->where('organization_id', $orgId),
            ],
            'taux_commission_proprietaire' => 'required|numeric|min:0|max:100',
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            'membres.*.nom' => 'required|string|max:255',
            'membres.*.prenom' => 'required|string|max:255',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            'membres.*.taux_commission' => 'required|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        if ($vehiculeSelectionne?->categorie === 'interne') {
            $data['proprietaire_id'] = null;
        }

        $this->validateUniquePhones($data['membres']);
        $this->validateTotalTaux($data['membres'], (float) $data['taux_commission_proprietaire']);
        $this->validateMembresExclusivite($data['membres'], $orgId, $equipes_livraison->id);

        DB::transaction(function () use ($data, $orgId, $equipes_livraison) {
            $equipes_livraison->update([
                'vehicule_id' => $data['vehicule_id'],
                'proprietaire_id' => $data['proprietaire_id'],
                'nom' => $data['nom'],
                'is_active' => $data['is_active'] ?? $equipes_livraison->is_active,
                'taux_commission_proprietaire' => $data['taux_commission_proprietaire'],
            ]);

            // Sync membres : suppression + recréation
            $equipes_livraison->membres()->delete();

            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId);
                EquipeLivreur::create([
                    'equipe_id' => $equipes_livraison->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'taux_commission' => $m['taux_commission'],
                    'ordre' => $m['ordre'] ?? $index,
                ]);
            }
        });

        return redirect()->route('equipes-livraison.edit', $equipes_livraison)
            ->with('success', 'Équipe mise à jour avec succès.');
    }

    public function destroy(EquipeLivraison $equipes_livraison): RedirectResponse
    {
        $this->authorize('delete', $equipes_livraison);

        $equipes_livraison->membres()->delete();
        $equipes_livraison->delete();

        return redirect()->route('equipes-livraison.index')
            ->with('success', 'Équipe supprimée.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function equipeData(EquipeLivraison $e): array
    {
        $membres = $e->relationLoaded('membres') ? $e->membres : $e->load('membres.livreur')->membres;
        $sorted = $membres->sortBy('ordre');
        $premierChauffeur = $sorted->firstWhere('role', 'chauffeur');

        $roleCounts = [];
        $membresData = $sorted->map(function (EquipeLivreur $m) use (&$roleCounts) {
            $role = $m->role;
            $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;

            return [
                'livreur_id' => $m->livreur_id,
                'nom' => $m->livreur?->nom ?? '',
                'prenom' => $m->livreur?->prenom ?? '',
                'telephone' => $m->livreur?->telephone ?? '',
                'role' => $role,
                'taux_commission' => (float) $m->taux_commission,
                'ordre' => $m->ordre,
                'numero' => $roleCounts[$role],
            ];
        })->values()->all();

        return [
            'id' => $e->id,
            'nom' => $e->nom,
            'is_active' => $e->is_active,
            'vehicule_id' => $e->vehicule_id,
            'vehicule_immatriculation' => $e->vehicule?->immatriculation,
            'vehicule_nom' => $e->vehicule?->nom_vehicule,
            'vehicule_type_label' => $e->vehicule?->type_label,
            'vehicule_categorie' => $e->vehicule?->categorie,
            'vehicule_capacite_packs' => $e->vehicule?->capacite_packs,
            'proprietaire_id' => $e->proprietaire_id,
            'proprietaire_nom' => $e->proprietaire ? trim("{$e->proprietaire->prenom} {$e->proprietaire->nom}") : null,
            'proprietaire_telephone' => $e->proprietaire?->telephone,
            'taux_commission_proprietaire' => $e->taux_commission_proprietaire !== null ? (float) $e->taux_commission_proprietaire : null,
            'nb_membres' => $membres->count(),
            'nb_convoyeurs' => $membres->where('role', 'convoyeur')->count(),
            'somme_taux' => (float) $membres->sum('taux_commission'),
            'premier_chauffeur_nom' => $premierChauffeur?->livreur ? trim($premierChauffeur->livreur->prenom.' '.$premierChauffeur->livreur->nom) : null,
            'premier_chauffeur_telephone' => $premierChauffeur?->livreur?->telephone,
            'membres' => $membresData,
        ];
    }

    private function vehiculesOptions(string $orgId, ?string $currentEquipeId = null): array
    {
        return Vehicule::with('proprietaire')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($currentEquipeId) {
                // Véhicules sans équipe
                $q->whereDoesntHave('equipe');
                // En édition : inclure aussi le véhicule de l'équipe courante
                if ($currentEquipeId) {
                    $q->orWhereHas('equipe', fn ($eq) => $eq->where('id', $currentEquipeId));
                }
            })
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => [
                'value' => $v->id,
                'label' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'categorie' => $v->categorie,
                'type_label' => $v->type_label,
                'proprietaire_id' => $v->proprietaire_id,
                'proprietaire_nom' => $v->proprietaire ? trim("{$v->proprietaire->prenom} {$v->proprietaire->nom}") : null,
            ])
            ->toArray();
    }

    private function proprietairesOptions(string $orgId): array
    {
        return Proprietaire::where('organization_id', $orgId)
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

    private function selectedVehicule(string $orgId, Request $request): ?Vehicule
    {
        $vehiculeId = $request->input('vehicule_id');
        if (! $vehiculeId) {
            return null;
        }

        return Vehicule::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->find($vehiculeId);
    }

    private function isVehiculeExterne(?Vehicule $vehicule): bool
    {
        return $vehicule?->categorie === 'externe';
    }

    private function currentSiteName(): string
    {
        $user = auth()->user();

        return ($user->sites()->wherePivot('is_default', true)->first()
            ?? $user->sites()->first())?->nom
            ?? $user->organization?->nom
            ?? '';
    }

    /**
     * Retrouve un livreur existant (par livreur_id ou par téléphone+org) ou en crée un nouveau.
     * Met à jour les données du livreur si elles ont changé.
     */
    private function resolveOrCreateLivreur(array $m, string $orgId): Livreur
    {
        if (! empty($m['livreur_id'])) {
            $livreur = Livreur::where('id', $m['livreur_id'])
                ->where('organization_id', $orgId)
                ->firstOrFail();

            $livreur->update([
                'nom' => $m['nom'],
                'prenom' => $m['prenom'],
                'telephone' => $m['telephone'],
            ]);

            return $livreur;
        }

        return Livreur::firstOrCreate(
            ['telephone' => $m['telephone'], 'organization_id' => $orgId],
            ['nom' => $m['nom'], 'prenom' => $m['prenom'], 'organization_id' => $orgId, 'is_active' => true]
        );
    }

    /**
     * Vérifie qu'aucun livreur (identifié par son téléphone) n'est déjà membre d'une autre équipe active.
     */
    private function validateMembresExclusivite(array $membres, string $orgId, ?string $equipeIdCourant = null): void
    {
        foreach ($membres as $index => $m) {
            $livreur = Livreur::where('telephone', $m['telephone'])
                ->where('organization_id', $orgId)
                ->first();

            if (! $livreur) {
                continue; // Nouveau livreur, pas encore affecté
            }

            $query = EquipeLivreur::query()
                ->where('livreur_id', $livreur->id)
                ->whereHas('equipe', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                );

            if ($equipeIdCourant !== null) {
                $query->where('equipe_id', '<>', $equipeIdCourant);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    "membres.{$index}.telephone" => 'Ce livreur est déjà affecté à une autre équipe.',
                ]);
            }
        }
    }

    private function validateUniquePhones(array $membres): void
    {
        $phones = array_map('trim', array_column($membres, 'telephone'));
        if (count($phones) !== count(array_unique($phones))) {
            abort(422, 'Deux membres ne peuvent pas avoir le même numéro de téléphone.');
        }
    }

    private function validateTotalTaux(array $membres, float $tauxProprietaire): void
    {
        $totalMembres = array_reduce(
            $membres,
            fn (float $sum, array $membre): float => $sum + (float) ($membre['taux_commission'] ?? 0),
            0.0
        );

        $total = $totalMembres + $tauxProprietaire;

        if (abs($total - 100.0) > 0.01) {
            abort(422, sprintf(
                'La répartition doit totaliser exactement 100 %% (livreurs + propriétaire). Actuellement : %.2f %%.',
                $total
            ));
        }
    }

    private function messages(): array
    {
        return [
            'nom.required' => "Le nom de l'équipe est obligatoire.",
            'nom.unique' => 'Une équipe avec ce nom existe déjà dans votre organisation.',
            'vehicule_id.required' => 'Le véhicule est obligatoire.',
            'vehicule_id.exists' => 'Le véhicule sélectionné est introuvable.',
            'vehicule_id.unique' => 'Ce véhicule est déjà affecté à une autre équipe.',
            'proprietaire_id.required' => 'Le propriétaire est obligatoire.',
            'proprietaire_id.exists' => "Le propriétaire sélectionné est introuvable ou n'appartient pas à votre organisation.",
            'taux_commission_proprietaire.required' => 'Le taux propriétaire est obligatoire.',
            'taux_commission_proprietaire.min' => 'Le taux propriétaire ne peut pas être négatif.',
            'taux_commission_proprietaire.max' => 'Le taux propriétaire ne peut pas dépasser 100 %.',
            'membres.required' => "L'équipe doit avoir au moins un membre.",
            'membres.min' => "L'équipe doit avoir au moins un membre.",
            'membres.*.nom.required' => 'Le nom du livreur est obligatoire.',
            'membres.*.prenom.required' => 'Le prénom du livreur est obligatoire.',
            'membres.*.telephone.required' => 'Le téléphone du livreur est obligatoire.',
            'membres.*.telephone.regex' => 'Le téléphone doit être au format guinéen (+224 suivi de 9 chiffres).',
            'membres.*.role.required' => 'Le rôle est obligatoire.',
            'membres.*.role.in' => 'Le rôle doit être chauffeur ou convoyeur.',
            'membres.*.taux_commission.required' => 'Le taux est obligatoire.',
            'membres.*.taux_commission.min' => 'Le taux ne peut pas être négatif.',
            'membres.*.taux_commission.max' => 'Le taux ne peut pas dépasser 100 %.',
        ];
    }
}
