<?php

namespace App\Enums;

enum NatureMontantRemuneration: string
{
    // Montant fixé à l'attribution au salarié.
    case FIXE = 'fixe';
    // Montant saisi chaque mois (ex. prime de rendement).
    case VARIABLE = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::FIXE => 'Fixe',
            self::VARIABLE => 'Variable (saisi chaque mois)',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $n) => ['value' => $n->value, 'label' => $n->label()], self::cases());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
