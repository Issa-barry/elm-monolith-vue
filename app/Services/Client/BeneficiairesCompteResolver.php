<?php

namespace App\Services\Client;

use App\Models\User;
use App\Services\Comptes\ActivitesCompteResolver;

/** Réunit les deux résolutions existantes, sans en changer aucune règle (ADR 0054). */
class BeneficiairesCompteResolver
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly ActivitesCompteResolver $activitesResolver,
    ) {}

    public function resolve(User $user): BeneficiairesCompte
    {
        return BeneficiairesCompte::depuis(
            $this->identityResolver->resolve($user),
            $this->activitesResolver->resolve($user),
        );
    }

    public function depuisIdentite(User $user, ClientIdentity $identite): BeneficiairesCompte
    {
        return BeneficiairesCompte::depuis($identite, $this->activitesResolver->resolve($user));
    }
}
