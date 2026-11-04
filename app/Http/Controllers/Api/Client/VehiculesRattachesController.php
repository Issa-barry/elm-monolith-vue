<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Client\BeneficiairesCompteResolver;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Data\VehiculeRattache;
use App\Services\Client\VehiculesRattachesService;
use App\Support\Client\VehiculeClientPresenter;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tous les véhicules rattachés au compte, quel que soit le lien (ADR 0054). */
class VehiculesRattachesController extends Controller
{
    #[Endpoint(
        title: 'Véhicules rattachés au compte (propriétaire, livreur, parrain)',
        description: 'Un véhicule par ligne, jamais dupliqué : `liens` porte chaque nature de rattachement '
            .'actuel (`proprietaire`, `livreur` de l\'équipe actuelle, `parrain`). Un consultant n\'a aucun '
            .'véhicule rattaché. Un véhicule quitté sort de la liste ; ses commissions restent dans '
            .'`GET /v1/mobile/commissions`. **Lecture seule.** `lecture_restreinte` = véhicule seulement '
            .'parrainé : identité du véhicule et nom du propriétaire, jamais ses coordonnées ni son '
            .'identifiant (`proprietaire.id`/`telephone` à `null`), ni l\'équipe, ni les capacités, ni '
            .'l\'état de livraison (`en_livraison` à `null`) ; ventes et dépenses de ce véhicule restent '
            .'fermées. `remuneration_parrain` : part du parrain en vigueur (GNF par pack) quand le compte le '
            .'parraine.',
    )]
    public function __invoke(
        Request $request,
        ClientIdentityResolver $identityResolver,
        BeneficiairesCompteResolver $beneficiairesResolver,
        VehiculesRattachesService $vehiculesRattaches,
        VehiculeClientPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $identity = $identityResolver->resolve($user);
        $enTransit = VehiculesController::enTransit($user);

        $rattaches = $vehiculesRattaches->pour(
            $beneficiairesResolver->depuisIdentite($user, $identity),
            VehiculeClientPresenter::RELATIONS,
        );

        return response()->json(
            $rattaches->map(fn (VehiculeRattache $r) => $presenter->rattache($r, $enTransit, $identity->proprietaire))->values()
        );
    }
}
