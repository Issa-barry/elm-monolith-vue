<?php

namespace App\Enums;

/**
 * Résout QUEL champ site lire sur l'opération source, pour un commission_groupe
 * ancré sur un site (ex: équipe dépôt). Décision AMOA actée : vente -> site de
 * l'opération, transfert/réception -> site destination.
 */
enum CommissionStrategieAncrageSite: string
{
    /** Un seul site porté par l'opération elle-même (ex: CommandeVente::site_id). */
    case OPERATION = 'operation';

    /** Site source d'un transfert à deux sites (réservé, non utilisé en V1). */
    case SOURCE = 'source';

    /** Site destination d'un transfert à deux sites (ex: TransfertLogistique::site_destination_id). */
    case DESTINATION = 'destination';

    public function label(): string
    {
        return match ($this) {
            self::OPERATION => 'Site de l\'opération',
            self::SOURCE => 'Site source',
            self::DESTINATION => 'Site destination',
        };
    }
}
