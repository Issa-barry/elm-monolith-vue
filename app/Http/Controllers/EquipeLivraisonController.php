<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Settings\CommissionRegleController;
use App\Models\Categorie;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionPartageLivraisonValidator;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $data = $request->validate($this->rules($request, $orgId, null, $vehiculeSelectionne), $this->messages());
        $this->validatePartagesCategorie(
            $data['partages_categorie'] ?? [],
            $orgId,
            $vehiculeSelectionne?->type_vehicule_id,
            $data['processus_code'],
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

            $this->syncPartagesCategorie($equipe->id, $data['partages_categorie'] ?? [], $livreurIdParOrdre, $orgId, $data['processus_code']);
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

        $data = $request->validate($this->rules($request, $orgId, $equipes_livraison->id, $vehiculeSelectionne), $this->messages());
        $this->validatePartagesCategorie(
            $data['partages_categorie'] ?? [],
            $orgId,
            $vehiculeSelectionne?->type_vehicule_id,
            $data['processus_code'],
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

            $this->syncPartagesCategorie($equipes_livraison->id, $data['partages_categorie'] ?? [], $livreurIdParOrdre, $orgId, $data['processus_code']);
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

    // ── Transfert de véhicule d'un livreur (changement d'équipe) ─────────────────
    //
    // Un livreur ne peut appartenir qu'à une seule équipe active (contrainte DB
    // equipe_livreurs.livreur_id unique) — "changer de véhicule" est donc un
    // déplacement entre deux équipes, jamais un simple champ à éditer. Règle métier
    // (décision AMOA, 02/09/2026) : le partage de commission par catégorie doit être
    // OBLIGATOIREMENT refait des deux côtés (départ ET arrivée), jamais repris
    // automatiquement de l'ancienne équipe — et l'opération est tout ou rien (un seul
    // commit final, cf. transferer()) : abandonner le wizard en cours de route ne
    // laisse aucune trace, le véhicule cible ne devient actif qu'au moment où le
    // transfert est effectivement validé.

    /**
     * Données du wizard de transfert — équipe de départ (catégories/processus à
     * re-répartir, membres restants) + liste des véhicules cibles possibles.
     */
    public function transfertDonnees(Livreur $livreur): JsonResponse
    {
        $orgId = auth()->user()->organization_id;
        abort_unless($livreur->organization_id === $orgId, 404);

        $membreActuel = EquipeLivreur::where('livreur_id', $livreur->id)
            ->whereHas('equipe', fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))
            ->with('equipe.vehicule')
            ->first();

        abort_if(! $membreActuel, 422, "Ce livreur n'appartient à aucune équipe active.");

        $equipeDepart = $membreActuel->equipe;
        $this->authorize('update', $equipeDepart);

        $vehiculeDepart = $equipeDepart->vehicule;
        $seraDissoute = EquipeLivreur::where('equipe_id', $equipeDepart->id)->count() <= 1;

        $codesApplicables = CommissionProcessusDefaults::codesApplicablesPourVehicule(
            $vehiculeDepart, CommissionRegleController::processusCodesDisponibles(),
        );

        return response()->json([
            'livreur' => [
                'id' => $livreur->id,
                'nom_complet' => $livreur->nom_complet,
                'role_actuel' => $membreActuel->role,
                'taux_commission_logistique_actuel' => $membreActuel->taux_commission_logistique !== null
                    ? (float) $membreActuel->taux_commission_logistique
                    : null,
            ],
            'equipe_depart' => [
                'id' => $equipeDepart->id,
                'vehicule_id' => $vehiculeDepart->id,
                'vehicule_nom' => $vehiculeDepart->nom_vehicule,
                'vehicule_immatriculation' => $vehiculeDepart->immatriculation,
                'sera_dissoute' => $seraDissoute,
                'membres_restants' => $seraDissoute ? [] : $this->membresAffichage($equipeDepart->id, $livreur->id),
                'partages' => $seraDissoute ? [] : $this->categoriesAvecPartageActif($equipeDepart, $codesApplicables),
            ],
            'vehicules_options' => Vehicule::with('equipe.membres')
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->where('id', '<>', $vehiculeDepart->id)
                ->orderBy('nom_vehicule')
                ->get()
                ->map(fn (Vehicule $v) => [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'a_une_equipe' => (bool) $v->equipe,
                    'nb_membres' => $v->equipe ? $v->equipe->membres->count() : 0,
                ])
                ->values(),
        ]);
    }

    /**
     * Données du wizard de transfert — équipe d'arrivée pour le véhicule cible choisi à
     * l'étape 1 : catégories/processus déjà configurés (à re-répartir) ou équipe
     * inexistante (nouvelle équipe créée à la volée, rien à re-répartir).
     */
    public function transfertDonneesArrivee(Livreur $livreur, Vehicule $vehicule): JsonResponse
    {
        $orgId = auth()->user()->organization_id;
        abort_unless($livreur->organization_id === $orgId && $vehicule->organization_id === $orgId, 404);

        $equipeArrivee = $vehicule->equipe;

        if (! $equipeArrivee) {
            $this->authorize('create', EquipeLivraison::class);

            return response()->json(['nouvelle_equipe' => true, 'membres_actuels' => [], 'partages' => []]);
        }

        $this->authorize('update', $equipeArrivee);

        $codesApplicables = CommissionProcessusDefaults::codesApplicablesPourVehicule(
            $vehicule, CommissionRegleController::processusCodesDisponibles(),
        );

        return response()->json([
            'nouvelle_equipe' => false,
            'equipe_id' => $equipeArrivee->id,
            'membres_actuels' => $this->membresAffichage($equipeArrivee->id, null),
            'partages' => $this->categoriesAvecPartageActif($equipeArrivee, $codesApplicables),
        ]);
    }

    /**
     * Exécute le transfert dans une seule transaction : rien n'est écrit tant que les
     * répartitions départ ET arrivée n'ont pas été validées — cf. commentaire de section.
     */
    public function transferer(Request $request, Livreur $livreur): RedirectResponse
    {
        $orgId = auth()->user()->organization_id;
        abort_unless($livreur->organization_id === $orgId, 404);

        $codesDisponibles = CommissionRegleController::processusCodesDisponibles();

        $data = $request->validate([
            'nouveau_vehicule_id' => [
                'required', 'string',
                Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            'taux_commission_logistique' => 'nullable|numeric|min:0|max:100',
            'partages_depart' => 'nullable|array',
            'partages_depart.*.processus_code' => ['required', Rule::in($codesDisponibles)],
            'partages_depart.*.categorie_id' => [
                'required', 'string',
                Rule::exists('categories', 'id')->where('organization_id', $orgId),
            ],
            'partages_depart.*.parts' => 'required|array|min:1',
            'partages_depart.*.parts.*.livreur_id' => ['required', 'string'],
            'partages_depart.*.parts.*.montant_unitaire' => 'required|integer|min:0',
            'partages_arrivee' => 'nullable|array',
            'partages_arrivee.*.processus_code' => ['required', Rule::in($codesDisponibles)],
            'partages_arrivee.*.categorie_id' => [
                'required', 'string',
                Rule::exists('categories', 'id')->where('organization_id', $orgId),
            ],
            'partages_arrivee.*.parts' => 'required|array|min:1',
            'partages_arrivee.*.parts.*.livreur_id' => ['required', 'string'],
            'partages_arrivee.*.parts.*.montant_unitaire' => 'required|integer|min:0',
        ], [
            'nouveau_vehicule_id.required' => 'Le véhicule cible est obligatoire.',
            'nouveau_vehicule_id.exists' => 'Le véhicule sélectionné est introuvable.',
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Le rôle doit être chauffeur ou convoyeur.',
            'partages_depart.*.parts.*.montant_unitaire.integer' => 'Le montant doit être un entier GNF, sans décimales.',
            'partages_arrivee.*.parts.*.montant_unitaire.integer' => 'Le montant doit être un entier GNF, sans décimales.',
        ]);

        $membreActuel = EquipeLivreur::where('livreur_id', $livreur->id)
            ->whereHas('equipe', fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))
            ->with('equipe.vehicule')
            ->first();
        abort_if(! $membreActuel, 422, "Ce livreur n'appartient à aucune équipe active.");

        $equipeDepart = $membreActuel->equipe;
        $vehiculeCible = Vehicule::where('organization_id', $orgId)->whereNull('deleted_at')->findOrFail($data['nouveau_vehicule_id']);

        abort_if($equipeDepart->vehicule_id === $vehiculeCible->id, 422, 'Le véhicule sélectionné est déjà celui de ce livreur.');

        $this->authorize('update', $equipeDepart);

        $equipeArriveeExistante = $vehiculeCible->equipe;
        $creeNouvelleEquipe = ! $equipeArriveeExistante;

        if ($creeNouvelleEquipe) {
            $this->authorize('create', EquipeLivraison::class);
        } else {
            $this->authorize('update', $equipeArriveeExistante);
        }

        $departSeraVide = EquipeLivreur::where('equipe_id', $equipeDepart->id)->count() <= 1;

        // Validation métier (somme exacte par catégorie/processus) AVANT toute écriture —
        // même séquencement que store()/update() : jamais de validation métier après le
        // début d'une transaction.
        $partagesDepartParProcessus = $departSeraVide ? collect() : collect($data['partages_depart'] ?? [])->groupBy('processus_code');
        foreach ($partagesDepartParProcessus as $processusCode => $partages) {
            $this->validatePartagesCategorie(
                $this->normaliserPartagesPourSync($partages),
                $orgId,
                $equipeDepart->vehicule->type_vehicule_id,
                $processusCode,
            );
        }

        $partagesArriveeParProcessus = $creeNouvelleEquipe ? collect() : collect($data['partages_arrivee'] ?? [])->groupBy('processus_code');
        foreach ($partagesArriveeParProcessus as $processusCode => $partages) {
            $this->validatePartagesCategorie(
                $this->normaliserPartagesPourSync($partages),
                $orgId,
                $vehiculeCible->type_vehicule_id,
                $processusCode,
            );
        }

        DB::transaction(function () use (
            $data, $orgId, $livreur, $membreActuel, $equipeDepart, $vehiculeCible,
            $creeNouvelleEquipe, $equipeArriveeExistante, $departSeraVide,
            $partagesDepartParProcessus, $partagesArriveeParProcessus,
        ) {
            // Verrou anti-concurrence : la ligne pivot n'a pas pu être déplacée entre
            // l'affichage du wizard et cette soumission.
            $ligne = EquipeLivreur::where('id', $membreActuel->id)->lockForUpdate()->first();
            abort_if(! $ligne || $ligne->equipe_id !== $equipeDepart->id, 409, 'Ce livreur a déjà été déplacé entre-temps, merci de réessayer.');

            $ligne->delete();

            if ($departSeraVide) {
                Vehicule::whereKey($equipeDepart->vehicule_id)->update(['is_active' => false]);
                $equipeDepart->delete();
            } else {
                foreach ($partagesDepartParProcessus as $processusCode => $partages) {
                    $this->syncPartagesCategorie(
                        $equipeDepart->id,
                        $this->normaliserPartagesPourSync($partages),
                        $this->livreurIdParOrdreIdentite($partages),
                        $orgId,
                        $processusCode,
                    );
                }
            }

            if ($creeNouvelleEquipe) {
                $equipeArrivee = EquipeLivraison::create([
                    'organization_id' => $orgId,
                    'vehicule_id' => $vehiculeCible->id,
                    'proprietaire_id' => $vehiculeCible->proprietaire_id,
                    'is_active' => true,
                ]);
                $ordre = 0;
            } else {
                $equipeArrivee = $equipeArriveeExistante;
                $ordre = ((int) EquipeLivreur::where('equipe_id', $equipeArrivee->id)->max('ordre')) + 1;
            }

            EquipeLivreur::create([
                'equipe_id' => $equipeArrivee->id,
                'livreur_id' => $livreur->id,
                'role' => $data['role'],
                'taux_commission_logistique' => $data['taux_commission_logistique'] ?? $membreActuel->taux_commission_logistique,
                'ordre' => $ordre,
            ]);

            foreach ($partagesArriveeParProcessus as $processusCode => $partages) {
                $this->syncPartagesCategorie(
                    $equipeArrivee->id,
                    $this->normaliserPartagesPourSync($partages),
                    $this->livreurIdParOrdreIdentite($partages),
                    $orgId,
                    $processusCode,
                );
            }

            Vehicule::whereKey($vehiculeCible->id)->update(['is_active' => true]);
        });

        return redirect()->route('vehicules.show', $vehiculeCible->id)
            ->with('success', 'Véhicule changé avec succès.');
    }

    /**
     * Roster affiché sur un écran de re-répartition — membres actuels de l'équipe, en
     * excluant éventuellement un livreur ($exclureLivreurId, le livreur en cours de
     * transfert côté équipe de départ). Jamais de montant ici : les écrans de transfert
     * démarrent toujours à 0 pour forcer une décision explicite (cf. commentaire de
     * section — pas de reprise automatique de l'ancien partage).
     */
    private function membresAffichage(string $equipeId, ?string $exclureLivreurId): array
    {
        return EquipeLivreur::where('equipe_id', $equipeId)
            ->when($exclureLivreurId, fn ($q) => $q->where('livreur_id', '<>', $exclureLivreurId))
            ->with('livreur')
            ->orderBy('ordre')
            ->get()
            ->map(fn (EquipeLivreur $m) => [
                'livreur_id' => $m->livreur_id,
                'nom_complet' => $m->livreur?->nom_complet,
                'role' => $m->role,
            ])
            ->values()
            ->all();
    }

    /**
     * Catégories ayant, pour cette équipe, un partage actif sur au moins un processus
     * applicable au véhicule — un jeu d'écrans de re-répartition à afficher par processus.
     * Une catégorie/processus jamais configuré n'apparaît jamais ici (reste "non
     * configuré", cf. docblock EquipeLivraisonPartageCategorie) : le transfert ne force
     * jamais à configurer ce qui ne l'était pas avant.
     *
     * @param  array<int, string>  $codesApplicables
     */
    private function categoriesAvecPartageActif(EquipeLivraison $equipe, array $codesApplicables): array
    {
        $orgId = $equipe->organization_id;
        $typeVehiculeId = $equipe->vehicule?->type_vehicule_id;

        $processusParCode = CommissionProcessus::where('organization_id', $orgId)
            ->whereIn('code', $codesApplicables)
            ->get()
            ->keyBy('code');

        $result = [];
        foreach ($codesApplicables as $code) {
            $processus = $processusParCode->get($code);
            if (! $processus) {
                continue;
            }

            $categorieIds = EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
                ->where('processus_id', $processus->id)
                ->whereNull('effective_to')
                ->distinct()
                ->pluck('categorie_id');

            if ($categorieIds->isEmpty()) {
                continue;
            }

            $categories = Categorie::whereIn('id', $categorieIds)->get()->keyBy('id');

            $result[] = [
                'processus_code' => $code,
                'processus_label' => CommissionRegleController::processusLabel($code),
                'categories' => $categorieIds->map(fn (string $categorieId) => [
                    'categorie_id' => $categorieId,
                    'categorie_nom' => $categories->get($categorieId)?->nom,
                    'enveloppe' => CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe(
                        $orgId, $processus->id, $categorieId, $typeVehiculeId, now(),
                    ),
                ])->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * Convertit le payload du wizard de transfert (parts identifiées par `livreur_id`,
     * tous des livreurs déjà résolus — jamais de nouveau membre créé ici) vers la forme
     * attendue par validatePartagesCategorie()/syncPartagesCategorie() (parts identifiées
     * par `membre_ordre`) — le `livreur_id` sert directement de clé, cf.
     * livreurIdParOrdreIdentite() ci-dessous, sans toucher à ces deux méthodes partagées
     * avec store()/update().
     */
    private function normaliserPartagesPourSync(Collection $partagesGroupProcessus): array
    {
        return $partagesGroupProcessus->map(fn (array $pc) => [
            'categorie_id' => $pc['categorie_id'],
            'parts' => collect($pc['parts'])->map(fn (array $p) => [
                'membre_ordre' => $p['livreur_id'],
                'montant_unitaire' => $p['montant_unitaire'],
            ])->all(),
        ])->values()->all();
    }

    private function livreurIdParOrdreIdentite(Collection $partagesGroupProcessus): array
    {
        return $partagesGroupProcessus
            ->flatMap(fn (array $pc) => collect($pc['parts'])->pluck('livreur_id'))
            ->unique()
            ->mapWithKeys(fn (string $id) => [$id => $id])
            ->all();
    }

    // ── Règles de validation, par moteur ─────────────────────────────────────

    private function rules(Request $request, string $orgId, ?string $excludeEquipeId, ?Vehicule $vehiculeSelectionne): array
    {
        // "Processus disponible" ≠ "processus obligatoire" (révisé le 31/08/2026) : un partage ne
        // peut être enregistré que pour un processus que l'USAGE du véhicule autorise réellement
        // (livraison_vente pour vente, livraison_logistique pour distribution_client/
        // logistique_transfert) — jamais uniquement filtré côté UI, une requête forgée avec
        // processus_code=logistique_transfert sur un véhicule Vente-only doit être rejetée ici même
        // (cf. CommissionProcessusDefaults::codesApplicablesPourVehicule(), source unique partagée
        // avec VehiculeController). Si le véhicule n'est pas encore résolu (vehicule_id invalide),
        // la liste complète reste la whitelist : l'erreur pertinente remonte via la règle
        // vehicule_id ci-dessous, jamais un faux positif sur processus_code.
        $codesApplicables = $vehiculeSelectionne
            ? CommissionProcessusDefaults::codesApplicablesPourVehicule($vehiculeSelectionne, CommissionRegleController::processusCodesDisponibles())
            : CommissionRegleController::processusCodesDisponibles();

        return [
            'is_active' => 'boolean',
            // Détermine quel partage (vente / logistique_transfert, cf.
            // CommissionRegleController::processusCodesDisponibles() — distribution_client n'est
            // plus un processus configurable depuis le 01/09/2026) cette soumission remplace —
            // jamais un fallback implicite vers vente. Chaque processus a ses propres montants
            // fixes pour la même équipe/catégorie (cf. syncPartagesCategorie()).
            'processus_code' => ['required', Rule::in($codesApplicables)],
            'vehicule_id' => [
                'required', 'string',
                Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                Rule::unique('equipes_livraison', 'vehicule_id')->whereNull('deleted_at')->ignore($excludeEquipeId),
            ],
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            'membres.*.nom_complet' => 'nullable|string|max:150',
            // Téléphone obligatoire et unique pour un chauffeur (identifie le
            // responsable légal du trajet) ; facultatif pour un convoyeur, qui
            // n'en possède pas toujours — cf. incident Sentry PHP-LARAVEL-66 :
            // rendre le champ obligatoire pour tous forçait la saisie de numéros
            // fictifs/réutilisés pour les convoyeurs sans téléphone, provoquant
            // des collisions avec de vrais numéros déjà enregistrés. Quand il est
            // renseigné, le format et l'unicité restent vérifiés pour tous les rôles
            // (cf. validateMembresExclusivite()).
            'membres.*.telephone' => [
                'nullable', 'string', 'regex:/^\+224\d{9}$/',
                // Rule "implicite" (ImplicitRule) : indispensable pour qu'elle
                // s'exécute même quand la valeur est vide — Laravel n'invoque
                // par défaut aucune règle non-implicite sur un champ nullable
                // resté null (cf. Validator::presentOrRuleIsImplicit()), ce qui
                // laisserait passer un chauffeur sans téléphone. ImplicitRule
                // n'expose que l'ancienne signature passes()/message() (pas
                // ValidationRule::validate()) dans cette version de Laravel.
                new class($request) implements ImplicitRule
                {
                    private string $errorMessage = '';

                    public function __construct(private Request $request) {}

                    public function passes($attribute, $value)
                    {
                        if (! preg_match('/^membres\.(\d+)\.telephone$/', $attribute, $m)) {
                            return true;
                        }
                        $role = $this->request->input("membres.{$m[1]}.role");
                        if ($role === 'chauffeur' && ($value === null || $value === '')) {
                            $this->errorMessage = 'Le téléphone est obligatoire pour un chauffeur.';

                            return false;
                        }

                        return true;
                    }

                    public function message()
                    {
                        return $this->errorMessage;
                    }
                },
            ],
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

        // Un convoyeur peut ne pas avoir de téléphone (cf. rules()) — telephone_normalise
        // reste alors NULL, ce que l'index unique (organization_id, telephone_normalise)
        // autorise en plusieurs exemplaires (NULL n'est jamais égal à NULL en SQL).
        $telephone = ! empty($m['telephone']) ? $m['telephone'] : null;
        $telephoneNormalise = $telephone !== null ? Personne::normaliserTelephone($telephone) : null;

        if (! empty($m['livreur_id'])) {
            $livreur = Livreur::where('id', $m['livreur_id'])
                ->where('organization_id', $orgId)
                ->firstOrFail();

            $livreur->update(['nom_complet' => $nomComplet]);
            $livreur->personne->update([
                'telephone' => $telephone,
                'telephone_normalise' => $telephoneNormalise,
            ]);

            return $livreur;
        }

        $personne = Personne::resoudreOuCreer($orgId, ['telephone' => $telephone]);

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
     * Source unique de la règle (CommissionPartageLivraisonValidator pour la validation de somme,
     * CommissionPartageLivraisonCategorieChecker pour la résolution d'enveloppe), rejouée à
     * l'identique par CommissionEnveloppeGenerator à la génération et par les garde-fous
     * préventifs à la création d'une opération (CommandeVenteController,
     * TransfertLogistiqueController) — jamais de formule dupliquée entre ces points d'entrée.
     */
    private function validatePartagesCategorie(
        array $partagesCategorie,
        string $orgId,
        ?string $typeVehiculeId,
        string $processusCode = CommissionProcessus::CODE_VENTE,
    ): void {
        if (empty($partagesCategorie)) {
            return;
        }

        $processus = CommissionProcessus::where('organization_id', $orgId)
            ->where('code', $processusCode)
            ->first();

        foreach ($partagesCategorie as $pc) {
            $enveloppe = CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe(
                $orgId,
                $processus?->id,
                $pc['categorie_id'],
                $typeVehiculeId,
                now(),
            );

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
    private function syncPartagesCategorie(
        string $equipeId,
        array $partagesCategorie,
        array $livreurIdParOrdre,
        string $orgId,
        string $processusCode = CommissionProcessus::CODE_VENTE,
    ): void {
        $maintenant = now();
        $processus = CommissionProcessusDefaults::resoudreOuCreer($orgId, $processusCode);

        // Scopée par processus : sans ce filtre, enregistrer le partage Vente fermerait aussi
        // silencieusement les partages Distribution/Transfert logistique de la même équipe.
        EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processus->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => $maintenant]);

        foreach ($partagesCategorie as $pc) {
            foreach ($pc['parts'] as $p) {
                EquipeLivraisonPartageCategorie::create([
                    'equipe_id' => $equipeId,
                    'processus_id' => $processus->id,
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
     * Vérifie, pour chaque membre dont le téléphone est renseigné, l'absence de
     * conflit avec une AUTRE Personne de l'organisation. Deux scénarios distincts :
     *
     * 1. `livreur_id` explicite (membre déjà identifié — resolveOrCreateLivreur() réécrit alors
     *    directement le téléphone de SA PROPRE Personne) dont le téléphone soumis appartient à
     *    une AUTRE Personne déjà en base : conflit d'IDENTITÉ direct, peu importe l'équipe ET
     *    peu importe le rôle de cette autre Personne (Livreur, mais aussi Proprietaire, Employe,
     *    User, Client...) — le téléphone est unique par organisation sur TOUTE la table
     *    `personnes` (contrainte personnes_organization_id_telephone_normalise_unique), jamais
     *    seulement entre livreurs. Avant un premier correctif (incident Sentry PHP-LARAVEL-66),
     *    ce cas n'était détecté que si l'autre livreur appartenait à une équipe DIFFÉRENTE ; le
     *    correctif avait ensuite limité la recherche du conflit à la seule table `livreurs`
     *    (`Livreur::where(...)`), ce qui laissait passer un conflit avec un autre rôle — ou avec
     *    un Livreur supprimé dont la Personne reste active — jusqu'à la mise à jour SQL, qui
     *    explosait alors en 500 au lieu d'un 422 propre (même incident, réapparu en prod le
     *    2026-09-02 pour cette raison précise).
     *
     * 2. `livreur_id` absent (nouveau membre, ou membre ré-identifié uniquement par téléphone —
     *    resolveOrCreateLivreur() réutilise alors la Personne existante via
     *    Personne::resoudreOuCreer(), aucun risque de collision SQL même si cette Personne porte
     *    déjà un autre rôle, cf. docblock de Personne sur le multi-rôle) : seule règle réellement
     *    applicable, un Livreur ne peut pas être emprunté à une équipe active DIFFÉRENTE de
     *    celle en cours d'édition (double affectation interdite).
     */
    private function validateMembresExclusivite(array $membres, string $orgId, ?string $equipeIdCourant = null): void
    {
        foreach ($membres as $index => $m) {
            if (empty($m['telephone'])) {
                continue;
            }

            $message = $this->detecterConflitTelephone($m['telephone'], $orgId, $m['livreur_id'] ?? null, $equipeIdCourant);

            if ($message !== null) {
                throw ValidationException::withMessages(["membres.{$index}.telephone" => $message]);
            }
        }
    }

    /**
     * Détecte un conflit de téléphone pour UN membre — logique unique partagée entre la
     * validation de soumission (validateMembresExclusivite, ci-dessus) et le contrôle live
     * pendant la saisie (verifierTelephone) : les deux points d'entrée ne doivent jamais
     * diverger (cf. incident Sentry PHP-LARAVEL-66). Retourne le message d'erreur à afficher,
     * ou null si aucun conflit.
     */
    private function detecterConflitTelephone(
        string $telephone,
        string $orgId,
        ?string $livreurIdSoumis,
        ?string $equipeIdCourant,
    ): ?string {
        $personneConflit = Personne::where('organization_id', $orgId)
            ->where('telephone_normalise', Personne::normaliserTelephone($telephone))
            ->first();

        if (! $personneConflit) {
            return null;
        }

        if ($livreurIdSoumis !== null) {
            $livreurActuel = Livreur::where('id', $livreurIdSoumis)->where('organization_id', $orgId)->first();

            // Le conflit désigne en réalité la propre Personne du membre édité (numéro
            // inchangé, ou reconfirmé) : rien à signaler.
            if ($livreurActuel && $personneConflit->id === $livreurActuel->personne_id) {
                return null;
            }

            return $personneConflit->livreur
                ? "Ce numéro appartient à {$personneConflit->livreur->nom_complet}."
                : "Ce numéro de téléphone est déjà utilisé par un autre contact de l'organisation.";
        }

        // Scénario 2 : seul un conflit avec un Livreur (pas un autre rôle, cf. docblock ci-dessus)
        // affecté à une autre équipe active est bloquant.
        $livreur = $personneConflit->livreur;
        if (! $livreur) {
            return null;
        }

        // Chargée avec son véhicule pour un message d'erreur exploitable — sans ce contexte,
        // l'utilisateur ne peut pas savoir de qui il s'agit ni où corriger (retirer le membre
        // de son équipe actuelle) avant de le réaffecter ici. L'immatriculation lève
        // l'ambiguïté quand plusieurs véhicules portent un nom proche.
        $autreEquipeLivreur = EquipeLivreur::query()
            ->where('livreur_id', $livreur->id)
            ->whereHas('equipe', fn ($q) => $q
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
            )
            ->when($equipeIdCourant !== null, fn ($q) => $q->where('equipe_id', '<>', $equipeIdCourant))
            ->with('equipe.vehicule')
            ->first();

        if (! $autreEquipeLivreur) {
            return null;
        }

        $vehicule = $autreEquipeLivreur->equipe?->vehicule;
        $vehiculeLabel = match (true) {
            $vehicule === null => 'véhicule inconnu',
            (bool) $vehicule->immatriculation => "{$vehicule->nom_vehicule} ({$vehicule->immatriculation})",
            default => $vehicule->nom_vehicule,
        };

        return "Ce numéro appartient à {$livreur->nom_complet} (déjà affecté au véhicule \"{$vehiculeLabel}\").";
    }

    /**
     * Vérification live d'un numéro pendant la saisie (avant soumission) — même règle que
     * detecterConflitTelephone(), appelée par le stepper (EquipeStepperModal.vue) au blur d'un
     * champ téléphone, pour signaler le conflit "en amont" plutôt qu'après un aller-retour
     * complet du formulaire (steps 2/3 + soumission).
     */
    public function verifierTelephone(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless(
            $user->can('equipes-livraison.create') || $user->can('equipes-livraison.update'),
            403,
        );

        $data = $request->validate([
            'telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'livreur_id' => ['nullable', 'string'],
            'equipe_id' => ['nullable', 'string'],
        ]);

        $message = $this->detecterConflitTelephone(
            $data['telephone'],
            $user->organization_id,
            $data['livreur_id'] ?? null,
            $data['equipe_id'] ?? null,
        );

        return response()->json([
            'conflict' => $message !== null,
            'message' => $message,
        ]);
    }

    private function validateUniquePhones(array $membres): void
    {
        // Les convoyeurs sans téléphone (champ facultatif) ne comptent jamais
        // comme un doublon entre eux — seuls les numéros réellement renseignés
        // doivent être uniques dans la soumission.
        $phones = array_filter(array_map(
            fn (array $m) => trim((string) ($m['telephone'] ?? '')),
            $membres
        ), fn (string $t) => $t !== '');

        if (count($phones) !== count(array_unique($phones))) {
            abort(422, 'Deux membres ne peuvent pas avoir le même numéro de téléphone.');
        }
    }

    private function messages(): array
    {
        return [
            'processus_code.required' => 'Le processus (Vente / Distribution client / Transfert logistique) est obligatoire.',
            'processus_code.in' => "Ce processus n'est pas applicable aux usages de ce véhicule.",
            'vehicule_id.required' => 'Le véhicule est obligatoire.',
            'vehicule_id.exists' => 'Le véhicule sélectionné est introuvable.',
            'vehicule_id.unique' => 'Ce véhicule est déjà affecté à une autre équipe.',
            'commission_unitaire_par_pack.required' => 'La commission par pack est obligatoire.',
            'commission_unitaire_par_pack.min' => 'La commission par pack doit être supérieure à 0.',
            'montant_par_pack_proprietaire.min' => 'Le montant propriétaire ne peut pas être négatif.',
            'membres.required' => "L'équipe doit avoir au moins un membre.",
            'membres.min' => "L'équipe doit avoir au moins un membre.",
            'membres.*.nom_complet.max' => 'Le nom complet ou surnom ne doit pas dépasser 150 caractères.',
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
