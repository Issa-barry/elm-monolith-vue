<?php

namespace App\Enums;

/**
 * Sens d'un `App\Models\MessageLog` — en P1 (cf. audit monitoring
 * Communications, 07/09/2026), seul `OUTBOUND` est réellement alimenté (SMS
 * OTP sortant vers Nimba) : ELM ne reçoit aucun SMS/WhatsApp entrant
 * aujourd'hui. `INBOUND` existe pour que l'écran de monitoring (filtre
 * Entrants/Sortants) ait un vrai fondement plutôt qu'une valeur inventée le
 * jour où un flux entrant sera réellement câblé.
 */
enum MessageDirection: string
{
    case OUTBOUND = 'outbound';
    case INBOUND = 'inbound';

    public function label(): string
    {
        return match ($this) {
            self::OUTBOUND => 'Sortant',
            self::INBOUND => 'Entrant',
        };
    }
}
