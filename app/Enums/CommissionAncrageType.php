<?php

namespace App\Enums;

/**
 * Type d'ancrage d'un commission_groupe. La VALEUR résolue pour un ancrage
 * SITE dépend du processus déclencheur (cf. CommissionStrategieAncrageSite) —
 * ce n'est jamais la même colonne selon qu'il s'agit d'une vente ou d'un
 * transfert logistique.
 */
enum CommissionAncrageType: string
{
    case VEHICULE = 'vehicule';
    case SITE = 'site';
    case ORGANISATION = 'organisation';

    public function label(): string
    {
        return match ($this) {
            self::VEHICULE => 'Véhicule',
            self::SITE => 'Site',
            self::ORGANISATION => 'Organisation',
        };
    }
}
