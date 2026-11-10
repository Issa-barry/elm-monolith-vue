<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Client\Operations\OperationTransfertResource;
use App\Http\Resources\Api\Client\Operations\OperationVenteResource;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Operations\OperationsCompte;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Détail d'une opération de la liste « Commandes » (ADR 0058) : même périmètre que la liste, deux
 * ressources séparées, aucune écriture. Lecture seule, comme tout l'espace client.
 */
class ShowOperationController extends Controller
{
    public function __construct(private readonly ClientIdentityResolver $identityResolver) {}

    #[Endpoint(
        description: 'Réponse wrappée `{"data": {...}}`. Le contenu dépend des `liens` du compte : prix, '
            .'total et paiement pour le client, quantités et paiement pour le propriétaire, quantités pour le '
            .'livreur, avec `contact` pendant la livraison seulement. `remise` n\'est renseignée que si le '
            .'système connaît la remise physique (réception ou retrait validés). `404` (jamais `403`) hors du '
            .'périmètre du compte : ne confirme jamais l\'existence de l\'opération.',
    )]
    public function __invoke(string $type, string $id): JsonResponse|OperationVenteResource|OperationTransfertResource
    {
        $operations = new OperationsCompte($this->identityResolver->resolve(request()->user()));

        if ($type === 'vente') {
            $vente = $operations->ventes()
                ->with(['site:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'client:id,nom,prenom,telephone,adresse,ville', 'lignes', 'facture'])
                ->whereKey($id)
                ->first();

            return $vente === null
                ? $this->introuvable()
                : new OperationVenteResource($vente, $operations->liensVentes(new Collection([$vente]))[$vente->id]);
        }

        $transfert = $operations->transferts()
            ->with(['siteSource:id,nom', 'siteDestination:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'lignes.variante.produit'])
            ->whereKey($id)
            ->first();

        return $transfert === null
            ? $this->introuvable()
            : new OperationTransfertResource($transfert, $operations->liensTransferts(new Collection([$transfert]))[$transfert->id]);
    }

    private function introuvable(): JsonResponse
    {
        return response()->json(['message' => 'Opération introuvable.'], 404);
    }
}
