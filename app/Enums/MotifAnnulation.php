<?php

namespace App\Enums;

enum MotifAnnulation: string
{
    case ERREUR_SAISIE = 'erreur_saisie';
    case DOUBLON = 'doublon';
    case RUPTURE_STOCK = 'rupture_stock';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::ERREUR_SAISIE => 'Erreur de saisie',
            self::DOUBLON => 'Doublon',
            self::RUPTURE_STOCK => 'Rupture de stock',
            self::AUTRE => 'Autre',
        };
    }

    public function toMotifString(string $detail = ''): string
    {
        if ($this === self::AUTRE) {
            return 'Autre: '.$detail;
        }

        return $this->label();
    }

    public static function validValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
