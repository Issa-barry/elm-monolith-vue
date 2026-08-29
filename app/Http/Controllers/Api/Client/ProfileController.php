<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Client\ProfileResource;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiche complète du profil métier de l'utilisateur connecté (proprietaire,
 * client ou livreur) — adresse/pays/ville comprises. Distinct de `/api/auth/me`
 * (identité minimale + IDs de contexte, appelé sur presque chaque écran) : cette
 * route porte les champs plus lourds/rarement affichés (localisation, raison
 * sociale, préférences de notification) que seul l'écran "Mon profil" a besoin
 * de charger.
 *
 * Priorité d'affichage si un compte cumule plusieurs profils (ex: proprietaire
 * ET client) : proprietaire > client > livreur, cohérent avec
 * ClientDashboardController::resolveQrPayload().
 */
class ProfileController extends Controller
{
    #[Endpoint(
        description: 'Aucun champ `siret`/identifiant légal (absent du modèle ELM, ne jamais '
            .'l\'inventer côté frontend). Aucun champ "quartier" séparé — seuls `pays`, `ville`, '
            .'`adresse` existent, un quartier doit être saisi dans `adresse` en texte libre. '
            .'`profile` vaut `null` si le rôle est présent mais qu\'aucune fiche métier n\'est '
            .'réellement rattachée (cas limite). Seuls `pays`/`code_pays`/`ville`/`adresse` sont '
            .'modifiables via `PATCH` — voir cet endpoint pour le détail exact.',
    )]
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $identity = $identityResolver->resolve($user);

        $resource = $identity->proprietaire ?? $identity->client ?? $identity->livreur;

        return response()->json((new ProfileResource($resource, $user))->resolve($request));
    }
}
