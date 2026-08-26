<?php

namespace App\Enums;

enum CommissionRegleStatut: string
{
    case ACTIVE = 'active';
    case REMPLACEE = 'remplacee';
    case BROUILLON = 'brouillon';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::REMPLACEE => 'Remplacée',
            self::BROUILLON => 'Brouillon',
        };
    }
}
