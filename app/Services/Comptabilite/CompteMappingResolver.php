<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Exceptions\Comptabilite\MappingComptableIndisponibleException;
use App\Models\CompteMapping;

/**
 * Résout un (compte, journal) configurés pour un événement+rôle donné.
 * Aucun numéro de compte n'est codé en dur : tout vient de compte_mappings,
 * une table de données paramétrable par organisation (règle #16 de la spec).
 */
class CompteMappingResolver
{
    public function resolve(string $organizationId, EvenementComptable $evenement, string $role, ?string $moyenPaiement = null): CompteMapping
    {
        $query = CompteMapping::query()
            ->where('organization_id', $organizationId)
            ->where('evenement', $evenement->value)
            ->where('role', $role)
            ->where('actif', true);

        $mapping = (clone $query)->where('moyen_paiement', $moyenPaiement)->first();

        // Pas de mapping spécifique à ce moyen de paiement : repli sur le mapping
        // par défaut (moyen_paiement NULL) de ce rôle, s'il existe.
        if (! $mapping && $moyenPaiement !== null) {
            $mapping = (clone $query)->whereNull('moyen_paiement')->first();
        }

        if (! $mapping) {
            throw MappingComptableIndisponibleException::pourRole($organizationId, $evenement->value, $role, $moyenPaiement);
        }

        return $mapping;
    }
}
