<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\UpdateProfileRequest;
use App\Http\Resources\Api\Client\ProfileResource;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;

/**
 * Met à jour la localisation (pays/ville/adresse) du profil métier de
 * l'utilisateur connecté — jamais son identité civile, ses identifiants de
 * connexion, ni son statut : cf. UpdateProfileRequest pour la justification du
 * périmètre. Écrit sur la bonne entité selon le profil réel (Personne pour
 * proprietaire/livreur, Client directement) — jamais une colonne dupliquée.
 */
class UpdateProfileController extends Controller
{
    #[Endpoint(
        description: 'Seuls `pays`, `code_pays`, `ville`, `adresse` sont modifiables ici (tous '
            .'optionnels, seuls les champs envoyés sont modifiés). **Volontairement non '
            .'modifiables par ce endpoint** (silencieusement ignorés si envoyés) : '
            .'`nom`/`prenom`/`surnom` (identité civile), `telephone`/`email` (identifiants de '
            .'connexion, unicité par organisation à revalider), `raison_sociale`/`type` (identité '
            .'légale), `actif` (jamais en self-service) — réservés au backoffice. `404` si aucun '
            .'profil n\'est rattaché au compte.',
    )]
    public function __invoke(
        UpdateProfileRequest $request,
        ClientIdentityResolver $identityResolver,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $identity = $identityResolver->resolve($user);

        $data = $request->validated();
        $resource = $identity->proprietaire ?? $identity->client ?? $identity->livreur;

        if ($resource === null) {
            return response()->json(['message' => 'Aucun profil rattaché à ce compte.'], 404);
        }

        $target = match (true) {
            $resource instanceof Proprietaire => $resource->personne,
            $resource instanceof Client => $resource,
            $resource instanceof Livreur => $resource->personne,
        };

        if ($target === null) {
            return response()->json(['message' => 'Aucun profil rattaché à ce compte.'], 404);
        }

        $target->update($data);

        return response()->json((new ProfileResource($resource->fresh(), $user))->resolve($request));
    }
}
