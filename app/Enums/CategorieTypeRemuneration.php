<?php

namespace App\Enums;

enum CategorieTypeRemuneration: string
{
    case PRIME = 'prime';
    case INDEMNITE = 'indemnite';
    case ALLOCATION = 'allocation';
    case AVANTAGE_NATURE = 'avantage_nature';

    public function label(): string
    {
        return match ($this) {
            self::PRIME => 'Prime',
            self::INDEMNITE => 'Indemnité',
            self::ALLOCATION => 'Allocation',
            self::AVANTAGE_NATURE => 'Avantage en nature',
        };
    }

    /**
     * Un avantage en nature est valorisé (valeur estimée, jamais versée dans le net à payer) ; les
     * autres catégories sont versées en argent.
     */
    public function estValorise(): bool
    {
        return $this === self::AVANTAGE_NATURE;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
