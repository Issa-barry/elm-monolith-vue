<?php

namespace App\Enums;

enum MotifAjustementStock: string
{
    case APRES_PRODUCTION = 'apres_production';
    case CORRECTION_STOCK = 'correction_stock';
    case PERTE = 'perte';
    case RETOUR = 'retour';
    case DON = 'don';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::APRES_PRODUCTION => 'Après production',
            self::CORRECTION_STOCK => 'Correction de stock',
            self::PERTE => 'Perte',
            self::RETOUR => 'Retour',
            self::DON => 'Don',
            self::AUTRE => 'Autre',
        };
    }

    public function toNotesString(string $detail = ''): string
    {
        if ($this === self::AUTRE) {
            return 'Autre : '.$detail;
        }

        return $this->label();
    }

    public static function validValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
