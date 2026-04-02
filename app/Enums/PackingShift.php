<?php

namespace App\Enums;

enum PackingShift: string
{
    case JOUR = 'jour';
    case NUIT = 'nuit';

    public function label(): string
    {
        return match ($this) {
            self::JOUR => 'Jour',
            self::NUIT => 'Nuit',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
