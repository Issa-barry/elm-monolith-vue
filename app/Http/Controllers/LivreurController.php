<?php

namespace App\Http\Controllers;

use App\Models\CommissionPart;
use App\Models\Livreur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LivreurController extends Controller
{
    /**
     * Liste lecture seule — la gestion s'effectue depuis les Équipes de livraison.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Livreur::class);

        $livreurs = Livreur::with('equipes')
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Livreur $l) => [
                'id' => $l->id,
                'nom' => $l->nom,
                'prenom' => $l->prenom,
                'nom_complet' => $l->nom_complet,
                'telephone' => $l->telephone,
                'is_active' => $l->is_active,
                'equipes' => $l->equipes->map(fn ($e) => [
                    'id' => $e->id,
                    'nom' => $e->nom,
                    'role' => $e->pivot->role,
                ])->values(),
            ]);

        return Inertia::render('Livreurs/Index', ['livreurs' => $livreurs]);
    }

    /**
     * Crée un livreur depuis la modal Équipe — retourne JSON.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Livreur::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'telephone' => [
                'required', 'string', 'max:30',
                Rule::unique('livreurs', 'telephone')->where('organization_id', $orgId),
            ],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.unique' => 'Ce numéro de téléphone est déjà utilisé dans votre organisation.',
        ]);

        $livreur = Livreur::create([...$data, 'organization_id' => $orgId, 'is_active' => true]);

        return response()->json([
            'id' => $livreur->id,
            'value' => $livreur->id,
            'label' => $livreur->nom_complet,
            'nom' => $livreur->nom,
            'prenom' => $livreur->prenom,
            'telephone' => $livreur->telephone,
            'is_active' => true,
        ], 201);
    }

    /**
     * Active / désactive un livreur depuis la fiche Équipe — retourne JSON.
     */
    public function toggle(Livreur $livreur): JsonResponse
    {
        $this->authorize('update', $livreur);

        $livreur->update(['is_active' => ! $livreur->is_active]);

        return response()->json(['is_active' => $livreur->is_active]);
    }

    /**
     * Supprime un livreur :
     *  - Suppression physique (soft) s'il n'a pas d'historique de commissions.
     *  - Désactivation logique s'il est référencé dans des commission_parts.
     */
    public function destroy(Livreur $livreur): JsonResponse
    {
        $this->authorize('delete', $livreur);

        $hasHistory = CommissionPart::where('livreur_id', $livreur->id)->exists();

        if ($hasHistory) {
            $livreur->update(['is_active' => false]);

            return response()->json([
                'action' => 'deactivated',
                'message' => 'Ce livreur est référencé dans des commissions. Il a été désactivé plutôt que supprimé.',
                'is_active' => false,
            ]);
        }

        $livreur->delete();

        return response()->json(['action' => 'deleted']);
    }
}
