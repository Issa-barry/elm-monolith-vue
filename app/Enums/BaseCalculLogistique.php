<?php

namespace App\Enums;

enum BaseCalculLogistique: string
{
    case FORFAIT = 'forfait';
    case PAR_PACK = 'par_pack';
    case PAR_KM = 'par_km';

    public function label(): string
    {
        return match ($this) {
            self::FORFAIT => 'Forfait fixe',
            self::PAR_PACK => 'Par pack livré',
            self::PAR_KM => 'Par kilomètre',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
