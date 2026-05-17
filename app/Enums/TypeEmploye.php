<?php

namespace App\Enums;

enum TypeEmploye: string
{
    case INTERNE = 'interne';
    case EXTERNE = 'externe';

    public function label(): string
    {
        return match ($this) {
            self::INTERNE => 'Interne',
            self::EXTERNE => 'Externe',
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
