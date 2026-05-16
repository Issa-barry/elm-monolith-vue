<?php

namespace App\Enums;

enum TypeContrat: string
{
    case CDI = 'cdi';
    case CDD = 'cdd';

    public function label(): string
    {
        return match ($this) {
            self::CDI => 'CDI',
            self::CDD => 'CDD',
        };
    }

    public function requiresDateFin(): bool
    {
        return $this === self::CDD;
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
