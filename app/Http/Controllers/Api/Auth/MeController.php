<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\QrPayloadResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    #[Endpoint(
        description: '`roles` liste **tous** les rôles du compte (un compte peut cumuler un rôle '
            .'staff ET un rôle client/proprietaire/livreur, cf. décision multi-rôle du 26/08/2026) — '
            .'**tableau non ordonné**, ne jamais utiliser `roles[0]` comme "rôle principal" (bug de '
            .'conception déjà rencontré côté frontend). Un frontend doit vérifier '
            ."`roles.some(r => ['client','proprietaire','livreur'].includes(r))` pour l'accès espace "
            .'client, jamais une égalité stricte. `context` est résolu exclusivement via '
            .'`ClientIdentityResolver` à partir du compte authentifié, jamais un paramètre client.',
    )]
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver, QrPayloadResolver $qrPayloadResolver): JsonResponse
    {
        $user = $request->user();
        $identity = $identityResolver->resolve($user);

        return response()->json([
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'telephone' => $user->telephone,
            'email' => $user->email,
            // Recast typé requis pour que Scramble documente roles[] comme un tableau de
            // noms — cf. commentaire identique dans LoginController::userResource().
            'roles' => $user->getRoleNames()->map(fn (string $r): string => $r)->values()->all(),
            'is_active' => $user->is_active,
            // Résolution centralisée dans QrPayloadResolver (cf. sa docblock) — avant
            // extraction, cette méthode cherchait le proprietaire/livreur par téléphone
            // SANS jamais tenter le user_id d'abord, et sans aucune restriction
            // d'organisation ni de "profil non réclamé" (cf. audit backend du 26/08/2026).
            'qr_payload' => $qrPayloadResolver->resolveForIdentity($identity),
            'context' => [
                'organization_id' => $identity->organizationId,
                'client_id' => $identity->client?->id,
                'proprietaire_id' => $identity->proprietaire?->id,
                'livreur_id' => $identity->livreur?->id,
            ],
        ]);
    }
}
