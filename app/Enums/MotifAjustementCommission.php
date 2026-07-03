<?php

namespace App\Enums;

enum MotifAjustementCommission: string
{
    case ABSENCE = 'absence';
    case REMPLACEMENT = 'remplacement';
    case TRAVAIL_SUPPLEMENTAIRE = 'travail_supplementaire';
    case CORRECTION = 'correction';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::ABSENCE => 'Absence',
            self::REMPLACEMENT => 'Remplacement',
            self::TRAVAIL_SUPPLEMENTAIRE => 'Travail supplémentaire',
            self::CORRECTION => 'Correction',
            self::AUTRE => 'Autre',
        };
    }

    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
