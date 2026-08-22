<?php

namespace App\Enums;

enum StatutFinancementAgence: string
{
    case COUVERT = 'couvert';
    case A_FINANCER = 'a_financer';
    case FONDS_EN_TRANSIT = 'fonds_en_transit';
    case DONNEES_INCOMPLETES = 'donnees_incompletes';

    public function label(): string
    {
        return match ($this) {
            self::COUVERT => 'Couvert',
            self::A_FINANCER => 'À financer',
            self::FONDS_EN_TRANSIT => 'Fonds en transit',
            self::DONNEES_INCOMPLETES => 'Données incomplètes',
        };
    }
}
