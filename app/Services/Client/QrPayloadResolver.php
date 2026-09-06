<?php

namespace App\Services\Client;

use App\Models\User;

/**
 * Construit le `qr_payload` (URL backoffice de la fiche propriétaire/livreur) à partir
 * d'une identité déjà résolue par ClientIdentityResolver — centralise une logique
 * jusqu'ici dupliquée entre MeController::resolveQrPayload() et
 * ClientDashboardController::resolveQrPayload() (même règle de priorité
 * proprietaire > livreur, cf. décision multi-rôle du 26/08/2026).
 */
class QrPayloadResolver
{
    public function __construct(private readonly ClientIdentityResolver $identityResolver) {}

    public function resolveForUser(User $user): ?string
    {
        return $this->resolveForIdentity($this->identityResolver->resolve($user));
    }

    public function resolveForIdentity(ClientIdentity $identity): ?string
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
