<?php

namespace App\Enums;

enum PeriodiciteRemuneration: string
{
    case MENSUELLE = 'mensuelle';
    case PONCTUELLE = 'ponctuelle';

    public function label(): string
    {
        return match ($this) {
            self::MENSUELLE => 'Mensuelle',
            self::PONCTUELLE => 'Ponctuelle',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label()], self::cases());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
