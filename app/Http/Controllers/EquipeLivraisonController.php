<?php

namespace App\Http\Controllers;

use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use App\Services\Commission\CommissionPartageLivraisonValidator;
use App\Services\Commission\CommissionRegleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

/**
 * Le propriétaire n'appartient pas au partage de commission : son montant vient
 * du barème Paramètres → Commissions. Les livreurs se partagent 100 % PAR
 * CATÉGORIE (equipe_livraison_partages_categorie), jamais un seul pourcentage
 * global — chaque catégorie ayant son propre barème Livraison, son partage
 * entre livreurs est lui aussi défini indépendamment. Absence de partage pour
 * une catégorie = non configuré, jamais déduit.
 */
class EquipeLivraisonController extends Controller
{
    public function index(): InertiaResponse
    {
        $this->authorize('viewAny', EquipeLivraison::class);

        $equipes = EquipeLivraison::with('membres.livreur', 'proprietaire', 'vehicule.typeVehicule', 'vehicule.capacites.categorie')
            ->where('organization_id', auth()->user()->organization_id)
            ->get()
            ->sortBy(fn (EquipeLivraison $e) => $e->vehicule?->nom_vehicule)
            ->values()
            ->map(fn (EquipeLivraison $e) => $this->equipeData($e));

        return Inertia::render('EquipesLivraison/Index', [
            'equipes' => $equipes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EquipeLivraison::class);

        $orgId = auth()->user()->organization_id;
        $vehiculeSelectionne = $this->selectedVehicule($orgId, $request);
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $proprietaireId = $vehiculeSelectionne?->proprietaire_id;
        $nomVehicule = $vehiculeSelectionne?->nom_vehicule ?? '';

        $data = $request->validate($this->rules($request, $orgId, null), $this->messages());
        $this->validatePartagesCategorie(
            $data['partages_categorie'] ?? [],
            $orgId,
            $vehiculeSelectionne?->type_vehicule_id,
        );
        $this->validateUniquePhones($data['membres']);
        $this->validateMembresExclusivite($data['membres'], $orgId);

        $equipe = null;
        DB::transaction(function () use ($data, $orgId, $proprietaireId, $nomVehicule, &$equipe) {
            $equipe = EquipeLivraison::create([
                'organization_id' => $orgId,
                'vehicule_id' => $data['vehicule_id'],
                'proprietaire_id' => $proprietaireId,
                'is_active' => $data['is_active'] ?? true,
            ]);

            Vehicule::whereKey($data['vehicule_id'])->update(['is_active' => true]);

            $designations = $this->designationsParDefaut($data['membres'], $nomVehicule);
            $livreurIdParOrdre = [];
            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId, $designations[$index]);
                $livreurIdParOrdre[$m['ordre'] ?? $index] = $livreur->id;

                EquipeLivreur::create([
                    'equipe_id' => $equipe->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'taux_commission_logistique' => $m['taux_commission_logistique'] ?? null,
                    'ordre' => $m['ordre'] ?? $index,
                ]);
            }

            $this->syncPartagesCategorie($equipe->id, $data['partages_categorie'] ?? [], $livreurIdParOrdre);
        });

        return redirect()->route('vehicules.show', $equipe->vehicule_id)
            ->with('success', 'Équipe créée avec succès.');
    }

    public function show(EquipeLivraison $equipes_livraison): InertiaResponse
    {
        $this->authorize('view', $equipes_livraison);

        $equipes_livraison->load('membres.livreur', 'proprietaire', 'vehicule.typeVehicule', 'vehicule.capacites.categorie');

        return Inertia::render('EquipesLivraison/Show', [
            'equipe' => $this->equipeData($equipes_livraison),
        ]);
    }

    public function update(Request $request, EquipeLivraison $equipes_livraison): RedirectResponse
    {
        $this->authorize('update', $equipes_livraison);

        $orgId = auth()->user()->organization_id;
        $vehiculeSelectionne = $this->selectedVehicule($orgId, $request);
        $proprietaireId = $vehiculeSelectionne?->proprietaire_id;
        $oldVehiculeId = $equipes_livraison->vehicule_id;
        $nomVehicule = $vehiculeSelectionne?->nom_vehicule ?? '';

        $data = $request->validate($this->rules($request, $orgId, $equipes_livraison->id), $this->messages());
        $this->validatePartagesCategorie(
            $data['partages_categorie'] ?? [],
            $orgId,
            $vehiculeSelectionne?->type_vehicule_id,
        );
        $this->validateUniquePhones($data['membres']);
        $this->validateMembresExclusivite($data['membres'], $orgId, $equipes_livraison->id);

        DB::transaction(function () use ($data, $orgId, $proprietaireId, $equipes_livraison, $oldVehiculeId, $nomVehicule) {
            $equipes_livraison->update([
                'vehicule_id' => $data['vehicule_id'],
                'proprietaire_id' => $proprietaireId,
                'is_active' => $data['is_active'] ?? $equipes_livraison->is_active,
            ]);

            if ($oldVehiculeId && $oldVehiculeId !== $data['vehicule_id']) {
                Vehicule::whereKey($oldVehiculeId)->update(['is_active' => false]);
            }
            Vehicule::whereKey($data['vehicule_id'])->update(['is_active' => true]);

            $equipes_livraison->membres()->delete();

            $designations = $this->designationsParDefaut($data['membres'], $nomVehicule);
            $livreurIdParOrdre = [];
            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId, $designations[$index]);
                $livreurIdParOrdre[$m['ordre'] ?? $index] = $livreur->id;

                EquipeLivreur::create([
                    'equipe_id' => $equipes_livraison->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'taux_commission_logistique' => $m['taux_commission_logistique'] ?? null,
                    'ordre' => $m['ordre'] ?? $index,
                ]);
            }

            $this->syncPartagesCategorie($equipes_livraison->id, $data['partages_categorie'] ?? [], $livreurIdParOrdre);
        });

        return redirect()->route('vehicules.show', $equipes_livraison->vehicule_id)
            ->with('success', 'Équipe mise à jour avec succès.');
    }

    public function destroy(EquipeLivraison $equipes_livraison): RedirectResponse
    {
        $this->authorize('delete', $equipes_livraison);

        if ($equipes_livraison->vehicule_id) {
            Vehicule::whereKey($equipes_livraison->vehicule_id)->update(['is_active' => false]);
        }

        $vehiculeId = $equipes_livraison->vehicule_id;

        $equipes_livraison->membres()->delete();
        $equipes_livraison->delete();

        if ($vehiculeId) {
            return redirect()->route('vehicules.show', $vehiculeId)
                ->with('success', 'Équipe supprimée.');
        }

        return redirect()->route('equipes-livraison.index')
            ->with('success', 'Équipe supprimée.');
    }

    // ── Règles de validation, par moteur ─────────────────────────────────────

    private function rules(Request $request, string $orgId, ?string $excludeEquipeId): array
    {
        return [
            'is_active' => 'boolean',
            'vehicule_id' => [
                'required', 'string',
                Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                Rule::unique('equipes_livraison', 'vehicule_id')->whereNull('deleted_at')->ignore($excludeEquipeId),
            ],
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            'membres.*.nom_complet' => 'nullable|string|max:150',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            'membres.*.taux_commission_logistique' => 'nullable|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
            // Partage Livreur PAR CATÉGORIE : chaque catégorie a son propre barème
            // Livreur (GNF/unité), son partage entre livreurs est donc défini
            // indépendamment, en montants GNF entiers fixes dont la somme doit égaler
            // exactement le barème (cf. validatePartagesCategorie(),
            // CommissionPartageLivraisonValidator — plus aucun pourcentage).
            'partages_categorie' => 'nullable|array',
            'partages_categorie.*.categorie_id' => [
                'required', 'string',
                Rule::exists('categories', 'id')->where('organization_id', $orgId),
            ],
            'partages_categorie.*.parts' => 'required|array|min:1',
            'partages_categorie.*.parts.*.montant_unitaire' => 'required|integer|min:0',
            'partages_categorie.*.parts.*.membre_ordre' => [
                'required', 'integer', 'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if ($value >= count($request->input('membres', []))) {
                        $fail("Le membre référencé (ordre {$value}) n'existe pas dans l'équipe.");
                    }
                },
            ],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function equipeData(EquipeLivraison $e): array
    {
        $membres = $e->relationLoaded('membres') ? $e->membres : $e->load('membres.livreur')->membres;
        $sorted = $membres->sortBy('ordre');

        $commission = (float) $e->commission_unitaire_par_pack;

        $premierChauffeur = $sorted->firstWhere('role', 'chauffeur');

        $roleCounts = [];
        $membresData = $sorted->map(function (EquipeLivreur $m) use (&$roleCounts) {
            $role = $m->role;
            $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
            $montant = (float) $m->montant_par_pack;

            return [
                'livreur_id' => $m->livreur_id,
                'nom_complet' => $m->livreur?->nom_complet,
                'telephone' => $m->livreur?->telephone ?? '',
                'role' => $role,
                'montant_par_pack' => $montant,
                'taux_commission' => (float) $m->taux_commission,
                'part_pourcentage' => (float) $m->taux_commission,
                'taux_commission_logistique' => $m->taux_commission_logistique !== null ? (float) $m->taux_commission_logistique : null,
                'ordre' => $m->ordre,
                'numero' => $roleCounts[$role],
            ];
        })->values()->all();

        return [
            'id' => $e->id,
            'is_active' => $e->is_active,
            'vehicule_id' => $e->vehicule_id,
            'vehicule_immatriculation' => $e->vehicule?->immatriculation,
            'vehicule_nom' => $e->vehicule?->nom_vehicule,
            'vehicule_type_label' => $e->vehicule?->type_label,
            'vehicule_livraison_vente' => $e->vehicule?->livraison_vente,
            'vehicule_livraison_logistique' => $e->vehicule?->livraison_logistique,
            'vehicule_capacites' => $e->vehicule
                ? $e->vehicule->capacites->map(fn (VehiculeCapacite $c) => [
                    'categorie_nom' => $c->categorie->nom,
                    'capacite_max' => $c->capacite_max,
                ])->values()->all()
                : [],
            'proprietaire_id' => $e->proprietaire_id,
            'proprietaire_nom' => $e->proprietaire ? trim("{$e->proprietaire->prenom} {$e->proprietaire->nom}") : null,
            'proprietaire_nom_affichage' => $e->proprietaire?->nom_affichage,
            'proprietaire_est_entreprise' => $e->proprietaire?->est_entreprise ?? false,
            'proprietaire_telephone' => $e->proprietaire?->telephone,
            'commission_unitaire_par_pack' => $commission,
            'montant_par_pack_proprietaire' => $e->montant_par_pack_proprietaire !== null ? (float) $e->montant_par_pack_proprietaire : null,
            'taux_commission_proprietaire' => $e->taux_commission_proprietaire !== null ? (float) $e->taux_commission_proprietaire : null,
            // "Chauffeur-1" si nom_complet est vide, jamais nul tant qu'un
            // chauffeur existe — sinon la colonne "Chauffeur" des tableaux
            // afficherait à tort "aucun chauffeur".
            'premier_chauffeur_nom' => $premierChauffeur
                ? (trim((string) ($premierChauffeur->livreur?->nom_complet ?? '')) !== ''
                    ? trim($premierChauffeur->livreur->nom_complet)
                    : 'Chauffeur-1')
                : null,
            'premier_chauffeur_telephone' => $premierChauffeur?->livreur?->telephone,
            'nb_membres' => $membres->count(),
            'nb_convoyeurs' => $membres->where('role', 'convoyeur')->count(),
            'somme_taux' => (float) $membres->sum('taux_commission'),
            'membres' => $membresData,
        ];
    }

    private function vehiculesOptions(string $orgId, ?string $currentEquipeId = null): array
    {
        return Vehicule::with('proprietaire')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($currentEquipeId) {
                $q->whereDoesntHave('equipe');
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
                'livraison_vente' => $v->livraison_vente,
                'livraison_logistique' => $v->livraison_logistique,
                'type_label' => $v->type_label,
                'proprietaire_id' => $v->proprietaire_id,
                'proprietaire_nom' => $v->proprietaire ? trim("{$v->proprietaire->prenom} {$v->proprietaire->nom}") : null,
            ])
            ->toArray();
    }

    private function proprietairesOptions(string $orgId): array
    {
        return Proprietaire::with('personne')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->get()
            ->sortBy('nom')
            ->map(fn (Proprietaire $p) => [
                'value' => $p->id,
                'label' => trim("{$p->prenom} {$p->nom}"),
                'telephone' => $p->telephone,
            ])
            ->values()
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
     *
     * Ne touche jamais aux colonnes nom/prenom (identité civile) : ce projet ne
     * les demande jamais dans son interface, donc le payload ne les envoie
     * jamais — les valeurs éventuellement déjà en base (autre usage/projet) ne
     * doivent pas être écrasées par leur absence dans la requête.
     *
     * $designationParDefaut (ex: "Chauffeur-1 Baba Ousou") remplace nom_complet
     * quand le champ est laissé vide dans le formulaire — jamais de nom_complet
     * vide en base, cf. Livreur::designationParDefaut().
     */
    private function resolveOrCreateLivreur(array $m, string $orgId, string $designationParDefaut): Livreur
    {
        $nomComplet = isset($m['nom_complet']) ? trim((string) $m['nom_complet']) : '';
        $nomComplet = $nomComplet !== '' ? $nomComplet : $designationParDefaut;

        if (! empty($m['livreur_id'])) {
            $livreur = Livreur::where('id', $m['livreur_id'])
                ->where('organization_id', $orgId)
                ->firstOrFail();

            $livreur->update(['nom_complet' => $nomComplet]);
            $livreur->personne->update([
                'telephone' => $m['telephone'],
                'telephone_normalise' => Personne::normaliserTelephone($m['telephone']),
            ]);

            return $livreur;
        }

        $personne = Personne::resoudreOuCreer($orgId, ['telephone' => $m['telephone']]);

        return Livreur::firstOrCreate(
            ['personne_id' => $personne->id, 'organization_id' => $orgId],
            ['nom_complet' => $nomComplet, 'is_active' => true]
        );
    }

    /**
     * Désignations "Chauffeur-1 {véhicule}"/"Convoyeur-2 {véhicule}" pour les
     * membres sans nom_complet saisi, indexées par position dans $membres —
     * jamais de nom_complet vide en base (cf. Livreur::designationParDefaut()).
     *
     * @param  array<int, array{role: string}>  $membres
     * @return array<int, string>
     */
    private function designationsParDefaut(array $membres, string $nomVehicule): array
    {
        $roleCounts = [];

        return array_map(function (array $m) use (&$roleCounts, $nomVehicule) {
            $roleCounts[$m['role']] = ($roleCounts[$m['role']] ?? 0) + 1;

            return Livreur::designationParDefaut($m['role'], $roleCounts[$m['role']], $nomVehicule);
        }, $membres);
    }

    /**
     * Vérifie que, POUR CHAQUE catégorie soumise, la somme des montants fixes
     * des livreurs égale exactement l'enveloppe du barème Livreur (GNF/unité)
     * — le propriétaire n'appartient jamais à ce partage (décision AMOA #1),
     * son montant vient du barème Paramètres → Commissions. Une catégorie
     * absente du payload n'est simplement pas validée ici (elle reste "non
     * configurée" pour cette équipe, cf. CommissionEnveloppeGenerator qui
     * bloque alors sa génération).
     *
     * Source unique de la règle (CommissionPartageLivraisonValidator), aussi
     * rejouée par CommissionEnveloppeGenerator à la génération — jamais de
     * formule dupliquée entre les deux points d'entrée.
     */
    private function validatePartagesCategorie(
        array $partagesCategorie,
        string $orgId,
        ?string $typeVehiculeId,
    ): void {
        if (empty($partagesCategorie)) {
            return;
        }

        $processus = CommissionProcessus::where('organization_id', $orgId)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->first();

        foreach ($partagesCategorie as $pc) {
            $regle = $processus
                ? CommissionRegleResolver::resolve(
                    $orgId,
                    $processus->id,
                    CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                    null,
                    null,
                    $pc['categorie_id'],
                    now(),
                    $typeVehiculeId,
                )
                : null;

            $enveloppe = (int) round((float) ($regle?->montant ?? 0));

            $membres = collect($pc['parts'])->map(fn (array $p) => (object) [
                'beneficiaire_id' => $p['membre_ordre'],
                'montant_unitaire' => $p['montant_unitaire'] ?? null,
            ]);

            try {
                CommissionPartageLivraisonValidator::valider($membres, $enveloppe);
            } catch (InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }
        }
    }

    /**
     * Remplace intégralement le partage Livreur par catégorie de cette équipe
     * — jamais de fusion partielle avec l'existant, exactement comme
     * $equipe->membres()->delete() + recréation pour les membres eux-mêmes.
     * $livreurIdParOrdre résout la position déclarée côté payload
     * (membre_ordre, jamais stable côté client pour un nouveau membre sans
     * livreur_id) vers le Livreur réellement créé/résolu par resolveOrCreateLivreur().
     *
     * Versionné (jamais de delete) : les lignes actives sont closes
     * (effective_to) puis les nouvelles insérées (effective_to NULL) — permet
     * à une relance de commission historique de résoudre le partage
     * réellement en vigueur à la date du fait générateur, pas la config
     * courante. part_pourcentage reçoit un placeholder 0 (colonne legacy en
     * cours de retrait, plus jamais lue par ce flux).
     */
    private function syncPartagesCategorie(string $equipeId, array $partagesCategorie, array $livreurIdParOrdre): void
    {
        $maintenant = now();

        EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->whereNull('effective_to')
            ->update(['effective_to' => $maintenant]);

        foreach ($partagesCategorie as $pc) {
            foreach ($pc['parts'] as $p) {
                EquipeLivraisonPartageCategorie::create([
                    'equipe_id' => $equipeId,
                    'categorie_id' => $pc['categorie_id'],
                    'livreur_id' => $livreurIdParOrdre[$p['membre_ordre']],
                    'part_pourcentage' => 0,
                    'montant_unitaire' => (int) $p['montant_unitaire'],
                    'effective_from' => $maintenant,
                    'effective_to' => null,
                ]);
            }
        }
    }

    /**
     * Vérifie qu'aucun livreur n'est déjà membre d'une autre équipe active.
     */
    private function validateMembresExclusivite(array $membres, string $orgId, ?string $equipeIdCourant = null): void
    {
        foreach ($membres as $index => $m) {
            $livreur = Livreur::where('organization_id', $orgId)
                ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', Personne::normaliserTelephone($m['telephone'])))
                ->first();

            if (! $livreur) {
                continue;
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

    private function messages(): array
    {
        return [
            'vehicule_id.required' => 'Le véhicule est obligatoire.',
            'vehicule_id.exists' => 'Le véhicule sélectionné est introuvable.',
            'vehicule_id.unique' => 'Ce véhicule est déjà affecté à une autre équipe.',
            'commission_unitaire_par_pack.required' => 'La commission par pack est obligatoire.',
            'commission_unitaire_par_pack.min' => 'La commission par pack doit être supérieure à 0.',
            'montant_par_pack_proprietaire.min' => 'Le montant propriétaire ne peut pas être négatif.',
            'membres.required' => "L'équipe doit avoir au moins un membre.",
            'membres.min' => "L'équipe doit avoir au moins un membre.",
            'membres.*.nom_complet.max' => 'Le nom complet ou surnom ne doit pas dépasser 150 caractères.',
            'membres.*.telephone.required' => 'Le téléphone du livreur est obligatoire.',
            'membres.*.telephone.regex' => 'Le téléphone doit être au format guinéen (+224 suivi de 9 chiffres).',
            'membres.*.role.required' => 'Le rôle est obligatoire.',
            'membres.*.role.in' => 'Le rôle doit être chauffeur ou convoyeur.',
            'membres.*.montant_par_pack.required' => 'Le montant par pack est obligatoire.',
            'membres.*.montant_par_pack.min' => 'Le montant par pack ne peut pas être négatif.',
            'partages_categorie.*.categorie_id.required' => 'La catégorie est obligatoire.',
            'partages_categorie.*.categorie_id.exists' => 'La catégorie sélectionnée est introuvable.',
            'partages_categorie.*.parts.required' => 'Le partage doit avoir au moins un bénéficiaire.',
            'partages_categorie.*.parts.*.montant_unitaire.required' => 'Le montant est obligatoire.',
            'partages_categorie.*.parts.*.montant_unitaire.integer' => 'Le montant doit être un entier GNF, sans décimales.',
            'partages_categorie.*.parts.*.montant_unitaire.min' => 'Le montant ne peut pas être négatif.',
        ];
    }
}
