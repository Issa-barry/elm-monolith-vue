<?php

namespace App\Enums;

enum StatutContrat: string
{
    case ACTIF = 'actif';
    case TERMINE = 'termine';
    case ROMPU = 'rompu';

    public function label(): string
    {
        return match ($this) {
            self::ACTIF => 'Actif',
            self::TERMINE => 'Terminé',
            self::ROMPU => 'Rompu',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn ($c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
