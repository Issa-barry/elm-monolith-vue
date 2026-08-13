<?php

namespace App\Http\Controllers;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Vehicule;
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

        $equipes = EquipeLivraison::with('membres.livreur', 'proprietaire', 'vehicule.typeVehicule')
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
            'proprietaire_id' => [
                Rule::requiredIf(fn () => $this->isVehiculeExterne($vehiculeSelectionne)),
                'nullable',
                'string',
                Rule::exists('proprietaires', 'id')->where('organization_id', $orgId),
            ],
            'commission_unitaire_par_pack' => 'required|numeric|min:1',
            'montant_par_pack_proprietaire' => [
                Rule::requiredIf(fn () => $this->isVehiculeExterne($vehiculeSelectionne)),
                'nullable', 'numeric', 'min:0',
            ],
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            // Nom civil (nom/prenom) jamais demandé côté Eau La Maman : seul un
            // nom complet ou surnom facultatif est saisi — voir Livreur::$fillable.
            'membres.*.nom_complet' => 'nullable|string|max:150',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            'membres.*.montant_par_pack' => 'required|numeric|min:0',
            // Barème logistique distinct du barème vente ci-dessus (montant_par_pack), optionnel
            // — laissé vide, le membre reçoit le même taux en transfert qu'en vente (cf.
            // EquipeLivreur::tauxCommissionLogistiqueEffectif()).
            'membres.*.taux_commission_logistique' => 'nullable|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        if (! $this->isVehiculeExterne($vehiculeSelectionne)) {
            $data['proprietaire_id'] = null;
            $data['montant_par_pack_proprietaire'] = null;
        }

        $commission = (float) $data['commission_unitaire_par_pack'];
        $isExterne = $this->isVehiculeExterne($vehiculeSelectionne);
        $montantProp = $isExterne ? (float) ($data['montant_par_pack_proprietaire'] ?? 0) : 0.0;

        $this->validatePartage($data['membres'], $commission, $montantProp, $isExterne);
        $this->validateUniquePhones($data['membres']);
        $this->validateMembresExclusivite($data['membres'], $orgId);

        $nomVehicule = $vehiculeSelectionne?->nom_vehicule ?? '';

        $equipe = null;
        DB::transaction(function () use ($data, $orgId, $commission, $montantProp, $isExterne, $nomVehicule, &$equipe) {
            $tauxProp = $commission > 0 ? round($montantProp / $commission * 100, 2) : 0.0;

            $equipe = EquipeLivraison::create([
                'organization_id' => $orgId,
                'vehicule_id' => $data['vehicule_id'],
                'proprietaire_id' => $data['proprietaire_id'],
                'is_active' => $data['is_active'] ?? true,
                'commission_unitaire_par_pack' => $commission,
                'montant_par_pack_proprietaire' => $isExterne ? $montantProp : null,
                'taux_commission_proprietaire' => $isExterne ? $tauxProp : 0.0,
            ]);

            Vehicule::whereKey($data['vehicule_id'])->update(['is_active' => true]);

            $designations = $this->designationsParDefaut($data['membres'], $nomVehicule);

            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId, $designations[$index]);
                $montant = (float) $m['montant_par_pack'];
                $taux = $commission > 0 ? round($montant / $commission * 100, 2) : 0.0;

                EquipeLivreur::create([
                    'equipe_id' => $equipe->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'montant_par_pack' => $montant,
                    'taux_commission' => $taux,
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
            'commission_unitaire_par_pack' => 'required|numeric|min:1',
            'montant_par_pack_proprietaire' => [
                Rule::requiredIf(fn () => $this->isVehiculeExterne($vehiculeSelectionne)),
                'nullable', 'numeric', 'min:0',
            ],
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|string',
            // Nom civil (nom/prenom) jamais demandé côté Eau La Maman : seul un
            // nom complet ou surnom facultatif est saisi — voir Livreur::$fillable.
            'membres.*.nom_complet' => 'nullable|string|max:150',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['chauffeur', 'convoyeur'])],
            'membres.*.montant_par_pack' => 'required|numeric|min:0',
            // Barème logistique distinct du barème vente ci-dessus (montant_par_pack), optionnel
            // — laissé vide, le membre reçoit le même taux en transfert qu'en vente (cf.
            // EquipeLivreur::tauxCommissionLogistiqueEffectif()).
            'membres.*.taux_commission_logistique' => 'nullable|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        if (! $this->isVehiculeExterne($vehiculeSelectionne)) {
            $data['proprietaire_id'] = null;
            $data['montant_par_pack_proprietaire'] = null;
        }

        $commission = (float) $data['commission_unitaire_par_pack'];
        $isExterne = $this->isVehiculeExterne($vehiculeSelectionne);
        $montantProp = $isExterne ? (float) ($data['montant_par_pack_proprietaire'] ?? 0) : 0.0;

        $this->validatePartage($data['membres'], $commission, $montantProp, $isExterne);
        $this->validateUniquePhones($data['membres']);
        $this->validateMembresExclusivite($data['membres'], $orgId, $equipes_livraison->id);

        $oldVehiculeId = $equipes_livraison->vehicule_id;
        $nomVehicule = $vehiculeSelectionne?->nom_vehicule ?? '';

        DB::transaction(function () use ($data, $orgId, $commission, $montantProp, $isExterne, $equipes_livraison, $oldVehiculeId, $nomVehicule) {
            $tauxProp = $commission > 0 ? round($montantProp / $commission * 100, 2) : 0.0;

            $equipes_livraison->update([
                'vehicule_id' => $data['vehicule_id'],
                'proprietaire_id' => $data['proprietaire_id'],
                'is_active' => $data['is_active'] ?? $equipes_livraison->is_active,
                'commission_unitaire_par_pack' => $commission,
                'montant_par_pack_proprietaire' => $isExterne ? $montantProp : null,
                'taux_commission_proprietaire' => $isExterne ? $tauxProp : 0.0,
            ]);

            if ($oldVehiculeId && $oldVehiculeId !== $data['vehicule_id']) {
                Vehicule::whereKey($oldVehiculeId)->update(['is_active' => false]);
            }
            Vehicule::whereKey($data['vehicule_id'])->update(['is_active' => true]);

            $equipes_livraison->membres()->delete();

            $designations = $this->designationsParDefaut($data['membres'], $nomVehicule);

            foreach ($data['membres'] as $index => $m) {
                $livreur = $this->resolveOrCreateLivreur($m, $orgId, $designations[$index]);
                $montant = (float) $m['montant_par_pack'];
                $taux = $commission > 0 ? round($montant / $commission * 100, 2) : 0.0;

                EquipeLivreur::create([
                    'equipe_id' => $equipes_livraison->id,
                    'livreur_id' => $livreur->id,
                    'role' => $m['role'],
                    'montant_par_pack' => $montant,
                    'taux_commission' => $taux,
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
                'montant_par_pack' => $montant,
                'taux_commission' => (float) $m->taux_commission,
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
            // Import flotte ne renseigne jamais capacite_packs sur le véhicule :
            // on retombe sur la capacité par défaut du type (cf. VehiculeController).
            'vehicule_capacite_packs' => $e->vehicule?->capacite_packs ?? $e->vehicule?->typeVehicule?->capacite_defaut,
            'proprietaire_id' => $e->proprietaire_id,
            'proprietaire_nom' => $e->proprietaire ? trim("{$e->proprietaire->prenom} {$e->proprietaire->nom}") : null,
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

    /**
     * "Externe" ici veut dire : propriété d'un tiers (pas l'organisation elle-même) — notion
     * de propriété, indépendante de l'usage (vente/logistique). Détermine si un propriétaire
     * réel et un partage montant_par_pack_proprietaire doivent être saisis pour cette équipe.
     */
    private function isVehiculeExterne(?Vehicule $vehicule): bool
    {
        if (! $vehicule?->proprietaire_id) {
            return false;
        }

        return $vehicule->proprietaire_id !== Proprietaire::interneParDefautId($vehicule->organization_id);
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

            $livreur->update([
                'nom_complet' => $nomComplet,
                'telephone' => $m['telephone'],
            ]);

            return $livreur;
        }

        return Livreur::firstOrCreate(
            ['telephone' => $m['telephone'], 'organization_id' => $orgId],
            ['nom_complet' => $nomComplet, 'organization_id' => $orgId, 'is_active' => true]
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
     * Vérifie que la somme des montants bénéficiaires = commission_unitaire_par_pack.
     */
    private function validatePartage(array $membres, float $commission, float $montantProp, bool $isExterne): void
    {
        $totalMembres = array_reduce(
            $membres,
            fn (float $sum, array $m): float => $sum + (float) ($m['montant_par_pack'] ?? 0),
            0.0
        );

        $total = $totalMembres + ($isExterne ? $montantProp : 0.0);

        if (abs($total - $commission) > 0.01) {
            abort(422, sprintf(
                'La somme des montants (%.0f GNF) doit être égale à la commission par pack (%.0f GNF).',
                $total,
                $commission
            ));
        }
    }

    /**
     * Vérifie qu'aucun livreur n'est déjà membre d'une autre équipe active.
     */
    private function validateMembresExclusivite(array $membres, string $orgId, ?string $equipeIdCourant = null): void
    {
        foreach ($membres as $index => $m) {
            $livreur = Livreur::where('telephone', $m['telephone'])
                ->where('organization_id', $orgId)
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
            'proprietaire_id.required' => 'Le propriétaire est obligatoire.',
            'proprietaire_id.exists' => "Le propriétaire sélectionné est introuvable ou n'appartient pas à votre organisation.",
            'commission_unitaire_par_pack.required' => 'La commission par pack est obligatoire.',
            'commission_unitaire_par_pack.min' => 'La commission par pack doit être supérieure à 0.',
            'montant_par_pack_proprietaire.required' => 'Le montant propriétaire est obligatoire.',
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
        ];
    }
}
