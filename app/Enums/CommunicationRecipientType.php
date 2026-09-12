<?php

namespace App\Enums;

/**
 * Nature du destinataire d'une `App\Models\CommunicationRule` — `CLIENT`
 * exige toujours un `App\Enums\ClientType` associé (cf.
 * CommunicationRule::client_type), `LIVREUR` ne connaît jamais de type
 * (destinataire unique, pas de sous-catégorie).
 */
enum CommunicationRecipientType: string
{
    case LIVREUR = 'livreur';
    case CLIENT = 'client';

    public function label(): string
    {
        return match ($this) {
            self::LIVREUR => 'Livreur',
            self::CLIENT => 'Client',
        };
    }
}
