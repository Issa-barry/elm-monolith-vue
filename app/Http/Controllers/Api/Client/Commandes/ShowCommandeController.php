<?php

namespace App\Http\Controllers\Api\Client\Commandes;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Client\CommandeVenteMineResource;
use App\Models\CommandeVente;
use App\Services\Client\ClientIdentityResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;

/**
 * "Mes commandes" — fiche détail. Résolution exclusivement via
 * `ClientIdentityResolver` → `identity->client` — jamais un `client_id`
 * fourni par l'appelant. Un compte sans profil Client (proprietaire/livreur
 * purs) reçoit un 404 ici (aucune commande ne peut jamais lui appartenir) —
 * cf. IndexCommandeController pour le contrat de la liste (vide, pas 404).
 */
class ShowCommandeController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
    ) {}

    #[Endpoint(
        description: 'Réponse wrappée `{"data": {...}}` (ressource unique, wrapping standard '
            .'Laravel) — contrairement à `index()` ci-dessus, non wrappée au-delà de la '
            .'pagination. Inclut les lignes de commande (`lignes[]`), absentes de la liste. '
            .'Utilise les **snapshots** enregistrés à la commande (`libelle_snapshot`, '
            .'`prix_vente_snapshot`), jamais une re-jointure vers le catalogue produit actuel '
            .'(un prix modifié depuis ne réécrit jamais l\'historique). `404` (jamais `403`) si la '
            .'commande n\'appartient pas au client résolu — ne confirme jamais son existence pour '
            .'un autre compte.',
    )]
    public function __invoke(string $commandeId): JsonResponse|CommandeVenteMineResource
    {
        $identity = $this->identityResolver->resolve(request()->user());

        if ($identity->client === null) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        $commande = CommandeVente::query()
            ->with(['vehicule:id,nom_vehicule,immatriculation', 'lignes'])
            ->where('id', $commandeId)
            ->where('client_id', $identity->client->id)
            ->when($identity->organizationId, fn ($q) => $q->where('organization_id', $identity->organizationId))
            ->first();

        if ($commande === null) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        return new CommandeVenteMineResource($commande);
    }
}
