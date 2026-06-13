<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\TransfertLogistique;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransfertsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = TransfertLogistique::query()
            ->with([
                'siteSource:id,nom',
                'siteDestination:id,nom',
                'vehicule:id,nom_vehicule,immatriculation',
                'equipeLivraison:id,nom',
                'lignes',
            ])
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id));

        if ($request->filled('statut')) {
            $query->where('statut', $request->query('statut'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(fn (Builder $q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('siteSource', fn ($q2) => $q2->where('nom', 'like', "%{$search}%"))
                ->orWhereHas('siteDestination', fn ($q2) => $q2->where('nom', 'like', "%{$search}%"))
            );
        }

        $transferts = $query->orderByDesc('updated_at')->get();

        return response()->json(TransfertResource::collection($transferts));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'site_source_id' => ['required', 'string', 'exists:sites,id'],
            'site_destination_id' => ['required', 'string', 'exists:sites,id', 'different:site_source_id'],
            'vehicule_id' => ['nullable', 'string', 'exists:vehicules,id'],
            'date_depart_prevue' => ['nullable', 'date'],
            'date_arrivee_prevue' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'string', 'exists:produits,id'],
            'lignes.*.quantite_demandee' => ['required', 'integer', 'min:1'],
        ]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $user->organization_id,
            'site_source_id' => $data['site_source_id'],
            'site_destination_id' => $data['site_destination_id'],
            'vehicule_id' => $data['vehicule_id'] ?? null,
            'date_depart_prevue' => $data['date_depart_prevue'] ?? null,
            'date_arrivee_prevue' => $data['date_arrivee_prevue'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['lignes'] as $ligne) {
            $transfert->lignes()->create([
                'produit_id' => $ligne['produit_id'],
                'quantite_demandee' => $ligne['quantite_demandee'],
            ]);
        }

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'lignes.produit:id,nom,code_interne',
        ]);

        return response()->json(new TransfertResource($transfert), 201);
    }

    public function show(Request $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id && $transfert->organization_id !== $user->organization_id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,nom',
            'lignes.produit:id,nom,code_interne,image_url',
            'commission.parts',
            'activites',
        ]);

        return response()->json(new TransfertResource($transfert));
    }
}
