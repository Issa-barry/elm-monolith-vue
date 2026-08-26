<?php

namespace App\Enums;

enum StatutReservationStock: string
{
    case ACTIVE = 'active';
    case CONSOMMEE = 'consommee';
    case LIBEREE = 'liberee';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::CONSOMMEE => 'Consommée',
            self::LIBEREE => 'Libérée',
        };
    }
}
