<?php

namespace App\Enums;

enum StatutLignePaie: string
{
    case EN_ATTENTE        = 'en_attente';
    case CALCULE           = 'calcule';
    case PARTIELLEMENT_PAYE = 'partiellement_paye';
    case PAYE              = 'paye';

    public function label(): string
    {
        return match($this) {
            self::EN_ATTENTE         => 'En attente',
            self::CALCULE            => 'Calculé',
            self::PARTIELLEMENT_PAYE => 'Part. payé',
            self::PAYE               => 'Payé',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
