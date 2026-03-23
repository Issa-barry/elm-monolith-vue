<?php

namespace App\Enums;

enum StatutCommission: string
{
    case EN_ATTENTE = 'en_attente';
    case PARTIELLE  = 'partielle';
    case VERSEE     = 'versee';
    case ANNULEE    = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::PARTIELLE  => 'Partielle',
            self::VERSEE     => 'Versée',
            self::ANNULEE    => 'Annulée',
        };
    }
}
