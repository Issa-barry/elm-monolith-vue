<?php

namespace App\Enums;

enum StatutPeriodeComptable: string
{
    case OUVERTE = 'ouverte';
    case CLOTUREE = 'cloturee';

    public function label(): string
    {
        return match ($this) {
            self::OUVERTE => 'Ouverte',
            self::CLOTUREE => 'Clôturée',
        };
    }
}
