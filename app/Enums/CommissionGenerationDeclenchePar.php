<?php

namespace App\Enums;

enum CommissionGenerationDeclenchePar: string
{
    case SYSTEME = 'systeme';
    case UTILISATEUR = 'utilisateur';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEME => 'Automatique',
            self::UTILISATEUR => 'Relance manuelle',
        };
    }
}
