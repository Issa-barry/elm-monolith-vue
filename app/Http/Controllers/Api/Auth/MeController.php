<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Client\ClientIdentity;
use App\Services\Client\ClientIdentityResolver;
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
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver): JsonResponse
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
            'qr_payload' => $this->resolveQrPayload($identity),
            'context' => [
                'organization_id' => $identity->organizationId,
                'client_id' => $identity->client?->id,
                'proprietaire_id' => $identity->proprietaire?->id,
                'livreur_id' => $identity->livreur?->id,
            ],
        ]);
    }

    /**
     * Avant ce correctif, cette méthode cherchait le proprietaire/livreur par
     * télephone SANS jamais tenter le user_id d'abord, et sans aucune restriction
     * d'organisation ni de "profil non réclamé" — un simple hasard de numéro de
     * téléphone avec un profil d'une autre organisation suffisait à faire pointer
     * qr_payload vers la fiche backoffice de quelqu'un d'autre (cf. audit backend du
     * 26/08/2026). Utilise désormais le même résolveur, avec les mêmes garde-fous,
     * que les autres endpoints Client\*.
     */
    private function resolveQrPayload(ClientIdentity $identity): ?string
    {
        if ($identity->proprietaire !== null) {
            return route('proprietaires.show', $identity->proprietaire->id);
        }

        if ($identity->livreur !== null) {
            return route('livreurs.show', $identity->livreur->id);
        }

        return null;
    }
}
