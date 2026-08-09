<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\TransfertLogistique;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
                'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
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
            'lignes.*.variante_id' => ['nullable', 'string', 'exists:produit_variantes,id'],
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
            $variante = $this->resolveVariante($ligne);

            $transfert->lignes()->create([
                'variante_id' => $variante->id,
                'quantite_demandee' => $ligne['quantite_demandee'],
            ]);
        }

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'lignes.variante:id,produit_id,sku',
            'lignes.variante.produit:id,nom',
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
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes.variante:id,produit_id,sku',
            // image_url est un accesseur (dérivé de produit_medias, pas une colonne) : on charge
            // la relation medias plutôt que de la lister dans un select() limité aux colonnes.
            'lignes.variante.produit:id,nom',
            'lignes.variante.produit.medias',
            'commission.parts',
            'activites',
        ]);

        return response()->json(new TransfertResource($transfert));
    }

    /**
     * Résout la variante à transférer. Le formulaire actuel ne propose qu'un sélecteur de
     * produit (pas encore de sélecteur de variante — Phase 3) ; si le produit n'a qu'une
     * seule variante (cas normal), on la prend directement.
     */
    private function resolveVariante(array $ligne): ProduitVariante
    {
        if (! empty($ligne['variante_id'])) {
            return ProduitVariante::findOrFail($ligne['variante_id']);
        }

        $produit = Produit::with('variantes')->findOrFail($ligne['produit_id']);

        if ($produit->variantes->count() === 1) {
            return $produit->variantes->first();
        }

        if ($produit->variantes->count() > 1) {
            throw ValidationException::withMessages([
                'lignes' => "Le produit « {$produit->nom} » a plusieurs déclinaisons — précisez la variante à transférer.",
            ]);
        }

        throw ValidationException::withMessages([
            'lignes' => "Le produit « {$produit->nom} » n'a aucune variante disponible.",
        ]);
    }
}
