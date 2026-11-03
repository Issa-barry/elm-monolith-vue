<?php

namespace App\Http\Controllers\Parrains;

use App\Http\Controllers\Controller;
use App\Models\Parrain;
use App\Support\Parrains\FicheParrainData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Liste et fiche des parrains au back-office (ADR 0050). Lecture seule : un parrain se crée et se
 * modifie toujours depuis son véhicule (PARRAIN-001, PARRAIN-005, PARRAIN-006).
 */
class ParrainController extends Controller
{
    public function index(Request $request, FicheParrainData $donnees): Response
    {
        $this->authorize('viewAny', Parrain::class);

        $filters = $request->validate([
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['ulid'],
            'statut' => ['nullable', Rule::in(['actif', 'inactif'])],
            'compte' => ['nullable', Rule::in(['rattache', 'non_rattache'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $parrains = Parrain::visiblesPar($user)
            ->with(['personne', 'user.personne'])
            ->withCount(['vehicules' => fn (Builder $q) => $donnees->limiterVehicules($q, $user)])
            ->when($filters['site_ids'] ?? null, fn (Builder $q, array $ids) => $q->dansAgences($ids))
            ->when($filters['statut'] ?? null, fn (Builder $q, string $statut) => $q->where('is_active', $statut === 'actif'))
            ->when($filters['compte'] ?? null, fn (Builder $q, string $compte) => $compte === 'rattache'
                ? $q->whereNotNull('user_id')
                : $q->whereNull('user_id'))
            ->when(trim($filters['search'] ?? ''), fn (Builder $q, string $search) => $q->whereHas(
                'personne',
                fn (Builder $p) => $p
                    ->where('nom_complet', 'like', "%{$search}%")
                    ->orWhere('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('surnom', 'like', "%{$search}%")
                    ->orWhere('telephone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"),
            ))
            ->get()
            ->sortBy(fn (Parrain $p) => mb_strtolower($p->nom_complet))
            ->values();

        return Inertia::render('Parrains/Index', [
            'parrains' => $donnees->liste($parrains, $user),
            'filters' => $filters,
            'peut_voir_commissions' => $donnees->peutVoirCommissions($user),
        ]);
    }

    public function show(Request $request, Parrain $parrain, FicheParrainData $donnees): Response
    {
        $this->authorize('view', $parrain);

        return Inertia::render('Parrains/Show', $donnees->fiche($parrain, $request->user()));
    }
}
