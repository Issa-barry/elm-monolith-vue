<?php

namespace App\Enums;

enum StatutSoldeOuverture: string
{
    case BROUILLON = 'brouillon';
    case VALIDE = 'valide';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDE => 'Validé',
        };
    }
}
