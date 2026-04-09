<?php

namespace App\Http\Controllers;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Proprietaire;
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

        $equipes = EquipeLivraison::with('membres.livreur', 'proprietaire')
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EquipeLivraison::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100', Rule::unique('equipes_livraison', 'nom')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'is_active' => 'boolean',
            'proprietaire_id' => ['required', 'integer', Rule::exists('proprietaires', 'id')->where('organization_id', $orgId)],
            'taux_commission_proprietaire' => 'required|numeric|min:0|max:100',
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|integer',
            'membres.*.nom' => 'required|string|max:255',
            'membres.*.prenom' => 'required|string|max:255',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['principal', 'assistant'])],
            'membres.*.taux_commission' => 'required|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        $this->validatePrincipal($data['membres']);
        $this->validateUniquePhones($data['membres']);
        $this->validateTotalTaux($data['membres'], (float) $data['taux_commission_proprietaire']);
        $this->validateMembresExclusivite($data['membres'], $orgId);

        DB::transaction(function () use ($data, $orgId) {
            $equipe = EquipeLivraison::create([
                'organization_id' => $orgId,
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
        $equipes_livraison->load('membres.livreur', 'proprietaire');

        return Inertia::render('EquipesLivraison/Edit', [
            'equipe' => $this->equipeData($equipes_livraison),
            'proprietaires' => $this->proprietairesOptions($orgId),
            'tauxProprietaireDefaut' => Parametre::getTauxProprietaireDefaut($orgId),
        ]);
    }

    public function update(Request $request, EquipeLivraison $equipes_livraison): RedirectResponse
    {
        $this->authorize('update', $equipes_livraison);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100', Rule::unique('equipes_livraison', 'nom')->where('organization_id', $orgId)->whereNull('deleted_at')->ignore($equipes_livraison->id)],
            'is_active' => 'boolean',
            'proprietaire_id' => ['required', 'integer', Rule::exists('proprietaires', 'id')->where('organization_id', $orgId)],
            'taux_commission_proprietaire' => 'required|numeric|min:0|max:100',
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|integer',
            'membres.*.nom' => 'required|string|max:255',
            'membres.*.prenom' => 'required|string|max:255',
            'membres.*.telephone' => ['required', 'string', 'regex:/^\+224\d{9}$/'],
            'membres.*.role' => ['required', Rule::in(['principal', 'assistant'])],
            'membres.*.taux_commission' => 'required|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        $this->validatePrincipal($data['membres']);
        $this->validateUniquePhones($data['membres']);
        $this->validateTotalTaux($data['membres'], (float) $data['taux_commission_proprietaire']);
        $this->validateMembresExclusivite($data['membres'], $orgId, $equipes_livraison->id);

        DB::transaction(function () use ($data, $orgId, $equipes_livraison) {
            $equipes_livraison->update([
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

        if ($equipes_livraison->vehicules()->where('is_active', true)->exists()) {
            return redirect()->back()
                ->withErrors(['equipe' => 'Cette équipe est assignée à un ou plusieurs véhicules actifs.']);
        }

        $equipes_livraison->membres()->delete();
        $equipes_livraison->delete();

        return redirect()->route('equipes-livraison.index')
            ->with('success', 'Équipe supprimée.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function equipeData(EquipeLivraison $e): array
    {
        $membres = $e->relationLoaded('membres') ? $e->membres : $e->load('membres.livreur')->membres;
        $principal = $membres->firstWhere('role', 'principal');

        return [
            'id' => $e->id,
            'nom' => $e->nom,
            'is_active' => $e->is_active,
            'proprietaire_id' => $e->proprietaire_id,
            'taux_commission_proprietaire' => $e->taux_commission_proprietaire !== null ? (float) $e->taux_commission_proprietaire : null,
            'nb_membres' => $membres->count(),
            'nb_assistants' => $membres->where('role', 'assistant')->count(),
            'somme_taux' => (float) $membres->sum('taux_commission'),
            'principal_nom' => $principal?->livreur ? trim($principal->livreur->prenom.' '.$principal->livreur->nom) : null,
            'principal_telephone' => $principal?->livreur?->telephone,
            'membres' => $membres->map(fn (EquipeLivreur $m) => [
                'livreur_id' => $m->livreur_id,
                'nom' => $m->livreur?->nom ?? '',
                'prenom' => $m->livreur?->prenom ?? '',
                'telephone' => $m->livreur?->telephone ?? '',
                'role' => $m->role,
                'taux_commission' => (float) $m->taux_commission,
                'ordre' => $m->ordre,
            ])->values()->all(),
        ];
    }

    private function proprietairesOptions(int $orgId): array
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

    /**
     * Retrouve un livreur existant (par livreur_id ou par téléphone+org) ou en crée un nouveau.
     * Met à jour les données du livreur si elles ont changé.
     */
    private function resolveOrCreateLivreur(array $m, int $orgId): Livreur
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
    private function validateMembresExclusivite(array $membres, int $orgId, ?int $equipeIdCourant = null): void
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

    private function validatePrincipal(array $membres): void
    {
        $count = count(array_filter($membres, fn ($m) => ($m['role'] ?? '') === 'principal'));

        if ($count === 0) {
            abort(422, "L'équipe doit avoir exactement un livreur principal.");
        }
        if ($count > 1) {
            abort(422, "L'équipe ne peut avoir qu'un seul livreur principal.");
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
            'membres.*.role.in' => 'Le rôle doit être principal ou assistant.',
            'membres.*.taux_commission.required' => 'Le taux est obligatoire.',
            'membres.*.taux_commission.min' => 'Le taux ne peut pas être négatif.',
            'membres.*.taux_commission.max' => 'Le taux ne peut pas dépasser 100 %.',
        ];
    }
}
