<?php

namespace App\Enums;

enum StatutPieceComptable: string
{
    case VALIDEE = 'validee';
    case CONTREPASSEE = 'contrepassee';

    public function label(): string
    {
        return match ($this) {
            self::VALIDEE => 'Validée',
            self::CONTREPASSEE => 'Contrepassée',
        };
    }
}
