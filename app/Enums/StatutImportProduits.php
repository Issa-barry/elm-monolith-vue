<?php

namespace App\Enums;

enum StatutImportProduits: string
{
    case ANALYSE = 'analyse';
    case EN_COURS = 'en_cours';
    case TERMINE = 'termine';
    case ECHOUE = 'echoue';

    public function label(): string
    {
        return match ($this) {
            self::ANALYSE => 'Analysé — en attente de confirmation',
            self::EN_COURS => 'En cours',
            self::TERMINE => 'Terminé',
            self::ECHOUE => 'Échoué',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
