<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Client\ClientIdentity;
use App\Services\Client\ClientIdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
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
            'roles' => $user->getRoleNames(),
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
