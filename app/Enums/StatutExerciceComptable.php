<?php

namespace App\Enums;

enum StatutExerciceComptable: string
{
    case OUVERT = 'ouvert';
    case CLOTURE = 'cloture';

    public function label(): string
    {
        return match ($this) {
            self::OUVERT => 'Ouvert',
            self::CLOTURE => 'Clôturé',
        };
    }
}
