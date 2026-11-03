<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Support\Collection;

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

    /**
     * Personnes portant l'identité de ce compte : celle du User (source de /me) et celles
     * de ses profils métier, normalement une seule.
     *
     * @return Collection<int, Personne>
     */
    public function personnesDu(User $user): Collection
    {
        return collect([
            $user->personne,
            $this->proprietaire?->personne,
            $this->client?->personne,
            $this->livreur?->personne,
        ])->filter()->unique('id')->values();
    }
}
