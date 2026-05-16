<?php

namespace App\Enums;

enum StatutEmploye: string
{
    case ACTIF     = 'actif';
    case SUSPENDU  = 'suspendu';
    case SORTI     = 'sorti';

    public function label(): string
    {
        return match ($this) {
            self::ACTIF    => 'Actif',
            self::SUSPENDU => 'Suspendu',
            self::SORTI    => 'Sorti',
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
