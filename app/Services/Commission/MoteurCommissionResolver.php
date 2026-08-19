<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Models\CommissionProcessus;

/**
 * Source de vérité UNIQUE pour savoir si une organisation fonctionne encore
 * sur l'ancien moteur (LEGACY, comportement financier historique intégralement
 * inchangé) ou a été explicitement basculée sur le nouveau (V2, barèmes fixes
 * par catégorie). Jamais de déduction implicite ailleurs dans le code —
 * chaque endroit qui a besoin de savoir "quel moteur pour cette organisation ?"
 * doit passer par cette classe, pas réinterroger commission_processus lui-même.
 *
 * Le statut ACTIF du processus "vente" sert délibérément de bascule unique :
 * c'est déjà lui qui gate CommissionEnveloppeGenerator::genererPourCommandeVente()
 * (cf. Phase 1). Une organisation ne peut donc jamais se retrouver dans un état
 * hybride où la popup écrirait des données V2 pendant que c'est encore l'ancien
 * moteur qui tourne réellement, ou l'inverse.
 */
class MoteurCommissionResolver
{
    public static function estV2(string $organizationId): bool
    {
        return CommissionProcessus::query()
            ->where('organization_id', $organizationId)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->where('statut', CommissionActivationStatut::ACTIF->value)
            ->exists();
    }

    public static function estLegacy(string $organizationId): bool
    {
        return ! self::estV2($organizationId);
    }
}
