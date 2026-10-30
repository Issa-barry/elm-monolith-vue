<?php

namespace App\Enums;

enum ModeCalculRemuneration: string
{
    case MONTANT_FIXE = 'montant_fixe';
    case POURCENTAGE_SALAIRE_BASE = 'pourcentage_salaire_base';

    public function label(): string
    {
        return match ($this) {
            self::MONTANT_FIXE => 'Montant fixe',
            self::POURCENTAGE_SALAIRE_BASE => 'Pourcentage du salaire de base',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
