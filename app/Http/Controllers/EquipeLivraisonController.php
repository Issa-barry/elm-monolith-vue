<?php

namespace App\Http\Controllers;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EquipeLivraisonController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', EquipeLivraison::class);

        $equipes = EquipeLivraison::with('membres.livreur')
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

        return Inertia::render('EquipesLivraison/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EquipeLivraison::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:100',
            'is_active' => 'boolean',
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|integer',
            'membres.*.nom' => 'required|string|max:255',
            'membres.*.prenom' => 'required|string|max:255',
            'membres.*.telephone' => 'required|string|max:30',
            'membres.*.role' => ['required', Rule::in(['principal', 'assistant'])],
            'membres.*.taux_commission' => 'required|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        $this->validatePrincipal($data['membres']);
        $this->validateUniquePhones($data['membres']);
        $this->validateTotalTaux($data['membres']);

        DB::transaction(function () use ($data, $orgId) {
            $equipe = EquipeLivraison::create([
                'organization_id' => $orgId,
                'nom' => $data['nom'],
                'is_active' => $data['is_active'] ?? true,
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

        $equipes_livraison->load('membres.livreur');

        return Inertia::render('EquipesLivraison/Edit', [
            'equipe' => $this->equipeData($equipes_livraison),
        ]);
    }

    public function update(Request $request, EquipeLivraison $equipes_livraison): RedirectResponse
    {
        $this->authorize('update', $equipes_livraison);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => 'required|string|max:100',
            'is_active' => 'boolean',
            'membres' => 'required|array|min:1',
            'membres.*.livreur_id' => 'nullable|integer',
            'membres.*.nom' => 'required|string|max:255',
            'membres.*.prenom' => 'required|string|max:255',
            'membres.*.telephone' => 'required|string|max:30',
            'membres.*.role' => ['required', Rule::in(['principal', 'assistant'])],
            'membres.*.taux_commission' => 'required|numeric|min:0|max:100',
            'membres.*.ordre' => 'nullable|integer|min:0',
        ], $this->messages());

        $this->validatePrincipal($data['membres']);
        $this->validateUniquePhones($data['membres']);
        $this->validateTotalTaux($data['membres']);

        DB::transaction(function () use ($data, $orgId, $equipes_livraison) {
            $equipes_livraison->update([
                'nom' => $data['nom'],
                'is_active' => $data['is_active'] ?? $equipes_livraison->is_active,
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

    private function validateTotalTaux(array $membres): void
    {
        $total = array_reduce(
            $membres,
            fn (float $sum, array $membre): float => $sum + (float) ($membre['taux_commission'] ?? 0),
            0.0
        );

        if ($total > 100.000001) {
            abort(422, "La somme des taux de l'équipe ne peut pas dépasser 100 %.");
        }
    }

    private function messages(): array
    {
        return [
            'nom.required' => "Le nom de l'équipe est obligatoire.",
            'membres.required' => "L'équipe doit avoir au moins un membre.",
            'membres.min' => "L'équipe doit avoir au moins un membre.",
            'membres.*.nom.required' => 'Le nom du livreur est obligatoire.',
            'membres.*.prenom.required' => 'Le prénom du livreur est obligatoire.',
            'membres.*.telephone.required' => 'Le téléphone du livreur est obligatoire.',
            'membres.*.role.required' => 'Le rôle est obligatoire.',
            'membres.*.role.in' => 'Le rôle doit être principal ou assistant.',
            'membres.*.taux_commission.required' => 'Le taux est obligatoire.',
            'membres.*.taux_commission.min' => 'Le taux ne peut pas être négatif.',
            'membres.*.taux_commission.max' => 'Le taux ne peut pas dépasser 100 %.',
        ];
    }
}
