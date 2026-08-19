<?php

namespace App\Http\Controllers;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

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

        $data = $request->validate([
            'is_active' => 'boolean',
            'vehicule_id' => [
                'required', 'string',
                Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                Rule::unique('equipes_livraison', 'vehicule_id')->whereNull('deleted_at'),
            ],
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            // Nom civil (nom/prenom) jamais demandé côté Eau La Maman : seul un
            // nom complet ou surnom facultatif est saisi — voir Livreur::$fillable.
            'membres.*.nom_complet' => 'nullable|string|max:150',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            // Part de l'enveloppe Livraison (%) — plus un montant GNF calé sur un ancien pot
            // partagé avec le propriétaire (cf. Phase 2 : le propriétaire n'appartient plus au
            // même partage, son montant vient désormais du barème Paramètres → Commissions).
            'membres.*.part_pourcentage' => 'required|numeric|min:0|max:100',
            // Barème logistique distinct du barème vente ci-dessus, optionnel — laissé vide, le
            // membre reçoit le même taux en transfert qu'en vente (cf.
            // EquipeLivreur::tauxCommissionLogistiqueEffectif()).
            'membres.*.taux_commission_logistique' => 'nullable|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        // Le propriétaire d'une équipe n'est jamais choisi indépendamment de celui du véhicule
        // (interne ou partenaire) : dérivé ici côté serveur, jamais fait confiance à une valeur
        // envoyée par le client — élimine toute possibilité de désynchronisation entre
        // Vehicule::proprietaire_id et EquipeLivraison::proprietaire_id.
        $proprietaireId = $vehiculeSelectionne?->proprietaire_id;

        $this->validatePartage($data['membres']);
        $this->validateUniquePhones($data['membres']);
        $this->validateMembresExclusivite($data['membres'], $orgId);

        $nomVehicule = $vehiculeSelectionne?->nom_vehicule ?? '';

        $equipe = null;
        DB::transaction(function () use ($data, $orgId, $proprietaireId, $nomVehicule, &$equipe) {
            // commission_unitaire_par_pack / montant_par_pack_proprietaire / taux_commission_proprietaire
            // ne sont plus alimentés depuis cette popup (Phase 2, cf. conception cible) — le montant
            // propriétaire vient désormais du barème Paramètres → Commissions, jamais d'un partage
            // mélangé avec les livreurs. Ces colonnes restent en base pour compatibilité historique
            // avec l'ancien moteur (CommissionCalculator), qui continue de lire leur DERNIÈRE valeur
            // connue sans plus jamais être mis à jour par ce contrôleur — gel volontaire et assumé.
            $equipe = EquipeLivraison::create([
                'organization_id' => $orgId,
                'vehicule_id' => $data['vehicule_id'],
                'proprietaire_id' => $proprietaireId,
                'is_active' => $data['is_active'] ?? true,
            ]);

            Vehicule::whereKey($data['vehicule_id'])->update(['is_active' => true]);

            $designations = $this->designationsParDefaut($data['membres'], $nomVehicule);

            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId, $designations[$index]);

                EquipeLivreur::create([
                    'equipe_id' => $equipe->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'taux_commission' => (float) $m['part_pourcentage'],
                    'taux_commission_logistique' => $m['taux_commission_logistique'] ?? null,
                    'ordre' => $m['ordre'] ?? $index,
                ]);
            }
        });

        $vehiculeId = $equipe->vehicule_id;

        return redirect()->route('vehicules.show', $vehiculeId)
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

        $data = $request->validate([
            'is_active' => 'boolean',
            'vehicule_id' => [
                'required', 'string',
                Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                Rule::unique('equipes_livraison', 'vehicule_id')->whereNull('deleted_at')->ignore($equipes_livraison->id),
            ],
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            // Nom civil (nom/prenom) jamais demandé côté Eau La Maman : seul un
            // nom complet ou surnom facultatif est saisi — voir Livreur::$fillable.
            'membres.*.nom_complet' => 'nullable|string|max:150',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            'membres.*.part_pourcentage' => 'required|numeric|min:0|max:100',
            // Barème logistique distinct du barème vente ci-dessus, optionnel — laissé vide, le
            // membre reçoit le même taux en transfert qu'en vente (cf.
            // EquipeLivreur::tauxCommissionLogistiqueEffectif()).
            'membres.*.taux_commission_logistique' => 'nullable|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        // Le propriétaire d'une équipe n'est jamais choisi indépendamment de celui du véhicule
        // (interne ou partenaire) : dérivé ici côté serveur, jamais fait confiance à une valeur
        // envoyée par le client — élimine toute possibilité de désynchronisation entre
        // Vehicule::proprietaire_id et EquipeLivraison::proprietaire_id (cf. bug historique où un
        // changement de catégorie/propriétaire du véhicule laissait un partage propriétaire
        // orphelin dans l'équipe).
        $proprietaireId = $vehiculeSelectionne?->proprietaire_id;

        $this->validatePartage($data['membres']);
        $this->validateUniquePhones($data['membres']);
        $this->validateMembresExclusivite($data['membres'], $orgId, $equipes_livraison->id);

        $oldVehiculeId = $equipes_livraison->vehicule_id;
        $nomVehicule = $vehiculeSelectionne?->nom_vehicule ?? '';

        DB::transaction(function () use ($data, $orgId, $proprietaireId, $equipes_livraison, $oldVehiculeId, $nomVehicule) {
            // Gel volontaire de commission_unitaire_par_pack / montant_par_pack_proprietaire /
            // taux_commission_proprietaire — cf. commentaire équivalent dans store().
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

            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId, $designations[$index]);

                EquipeLivreur::create([
                    'equipe_id' => $equipes_livraison->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'taux_commission' => (float) $m['part_pourcentage'],
                    'taux_commission_logistique' => $m['taux_commission_logistique'] ?? null,
                    'ordre' => $m['ordre'] ?? $index,
                ]);
            }
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
                // Legacy (Phase 1, plus jamais alimenté par ce contrôleur) — conservé pour ne
                // pas casser les pages EquipesLivraison/Index|Show non concernées par la Phase 2.
                'montant_par_pack' => $montant,
                'taux_commission' => (float) $m->taux_commission,
                // Alias Phase 2, sémantiquement identique à taux_commission (part de l'enveloppe
                // Livraison, plus jamais relative à un pot partagé avec le propriétaire).
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
     * Vérifie que la somme des parts des livreurs totalise 100 % — le propriétaire n'appartient
     * plus à ce partage (Phase 2, cf. conception cible décision AMOA #1) : son montant vient
     * désormais du barème Paramètres → Commissions, jamais d'un pot partagé avec l'équipe.
     */
    private function validatePartage(array $membres): void
    {
        $total = array_reduce(
            $membres,
            fn (float $sum, array $m): float => $sum + (float) ($m['part_pourcentage'] ?? 0),
            0.0
        );

        if (abs($total - 100.0) > 0.01) {
            abort(422, sprintf(
                'La somme des parts (%.2f %%) doit être égale à 100 %%.',
                $total
            ));
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
            'membres.required' => "L'équipe doit avoir au moins un membre.",
            'membres.min' => "L'équipe doit avoir au moins un membre.",
            'membres.*.nom_complet.max' => 'Le nom complet ou surnom ne doit pas dépasser 150 caractères.',
            'membres.*.telephone.required' => 'Le téléphone du livreur est obligatoire.',
            'membres.*.telephone.regex' => 'Le téléphone doit être au format guinéen (+224 suivi de 9 chiffres).',
            'membres.*.role.required' => 'Le rôle est obligatoire.',
            'membres.*.role.in' => 'Le rôle doit être chauffeur ou convoyeur.',
            'membres.*.part_pourcentage.required' => 'La part est obligatoire.',
            'membres.*.part_pourcentage.min' => 'La part ne peut pas être négative.',
            'membres.*.part_pourcentage.max' => 'La part ne peut pas dépasser 100 %.',
        ];
    }
}
