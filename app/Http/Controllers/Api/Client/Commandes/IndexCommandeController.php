<?php

namespace App\Http\Controllers\Api\Client\Commandes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\CommandesMineRequest;
use App\Http\Resources\Api\Client\CommandeVenteMineResource;
use App\Models\CommandeVente;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Mes commandes" — espace du rôle `client` (achats), premier endpoint API
 * dédié à ce rôle (tous les autres endpoints Client\* sont orientés
 * proprietaire/livreur, cf. gap documenté en §8 de
 * docs/api-espace-client-contract.md). Résolution exclusivement via
 * `ClientIdentityResolver` → `identity->client` — jamais un `client_id` fourni
 * par l'appelant. Un compte sans profil Client (proprietaire/livreur purs)
 * reçoit une liste vide (cohérent avec le reste de l'API : un profil non
 * applicable renvoie du vide, pas une erreur) — cf. ShowCommandeController
 * pour le contrat de la fiche détail (404, pas de liste vide).
 */
class IndexCommandeController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
    ) {}

    public function __invoke(CommandesMineRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $identity = $this->identityResolver->resolve($user);
        $filters = $request->filters();

        if ($identity->client === null) {
            return CommandeVenteMineResource::collection(
                CommandeVente::query()->whereRaw('1 = 0')->paginate($request->perPage())
            )->additional(['filters' => $filters]);
        }

        $commandes = CommandeVente::query()
            ->with('vehicule:id,nom_vehicule,immatriculation')
            ->where('client_id', $identity->client->id)
            ->when($identity->organizationId, fn ($q) => $q->where('organization_id', $identity->organizationId))
            ->when($filters['statut'], fn ($q) => $q->where('statut', $filters['statut']))
            ->when($filters['date_debut'], fn ($q) => $q->whereDate('validated_at', '>=', $filters['date_debut']))
            ->when($filters['date_fin'], fn ($q) => $q->whereDate('validated_at', '<=', $filters['date_fin']))
            ->orderByDesc('created_at')
            ->paginate($request->perPage())
            ->withQueryString();

        return CommandeVenteMineResource::collection($commandes)->additional(['filters' => $filters]);
    }
}
