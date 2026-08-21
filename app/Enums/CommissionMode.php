<?php

namespace App\Enums;

/**
 * Une enveloppe DIRECT appartient à un bénéficiaire unique par construction.
 * Une enveloppe A_REPARTIR appartient à un commission_groupe, réparti entre
 * ses membres actifs (CommissionRepartitionEngine).
 */
enum CommissionMode: string
{
    case DIRECT = 'direct';
    case A_REPARTIR = 'a_repartir';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT => 'Directe',
            self::A_REPARTIR => 'À répartir',
        };
    }
}
