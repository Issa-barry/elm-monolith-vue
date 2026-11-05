<?php

namespace App\Http\Controllers\Api\Mobile\Logistique;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\Logistique\LivreurCompteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LivraisonDetailController extends Controller
{
    public function __construct(private readonly LivreurCompteResolver $livreurs) {}

    public function __invoke(Request $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->livreurs->concerne($user, $transfert)) {
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
}
