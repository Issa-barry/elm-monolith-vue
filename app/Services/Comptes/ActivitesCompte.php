<?php

namespace App\Services\Comptes;

use App\Models\Parrain;
use App\Models\Prestataire;
use Illuminate\Support\Collection;

/**
 * Résultat de ActivitesCompteResolver::resolve() — les activités parrain et consultant rattachées
 * à un compte authentifié.
 */
final class ActivitesCompte
{
    /**
     * @param  Collection<int, Prestataire>  $consultants
     */
    public function __construct(
        public readonly ?string $organizationId,
        public readonly ?Parrain $parrain,
        public readonly Collection $consultants,
    ) {}

    /** Prestataire consultant si ce compte y a accès, sinon null : l'appelant répond 404. */
    public function consultant(string $prestataireId): ?Prestataire
    {
        return $this->consultants->firstWhere('id', $prestataireId);
    }
}
