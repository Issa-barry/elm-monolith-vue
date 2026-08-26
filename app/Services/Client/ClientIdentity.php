<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;

/**
 * Résultat de ClientIdentityResolver::resolve() — le périmètre métier (organisation
 * + profils) rattaché à un utilisateur authentifié.
 */
final class ClientIdentity
{
    public function __construct(
        public readonly ?string $organizationId,
        public readonly ?Client $client,
        public readonly ?Proprietaire $proprietaire,
        public readonly ?Livreur $livreur,
    ) {}

    public function hasProfile(): bool
    {
        return $this->client !== null || $this->proprietaire !== null || $this->livreur !== null;
    }
}
