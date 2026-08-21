<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Models\CommissionGroupe;

/**
 * Résout un CommissionGroupe par (organisation, type, ancrage) — jamais par
 * nom, jamais par branchement `if type == ...` : le type d'ancrage
 * (véhicule/site/organisation) est une donnée, pas du code.
 */
class CommissionGroupeResolver
{
    public static function resolve(string $organizationId, string $type, string $ancrageType, string $ancrageId): ?CommissionGroupe
    {
        return CommissionGroupe::query()
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->where('ancrage_type', $ancrageType)
            ->where('ancrage_id', $ancrageId)
            ->where('statut', CommissionActivationStatut::ACTIF->value)
            ->first();
    }
}
