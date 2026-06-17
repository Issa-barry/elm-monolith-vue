<?php

namespace App\Enums;

enum StatutFichePaiement: string
{
    case A_PAYER = 'a_payer';
    case PARTIELLEMENT_PAYE = 'partiellement_paye';
    case PAYE = 'paye';

    public function label(): string
    {
        return match ($this) {
            self::A_PAYER => 'À payer',
            self::PARTIELLEMENT_PAYE => 'Partiellement payé',
            self::PAYE => 'Payé',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::A_PAYER => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
            self::PARTIELLEMENT_PAYE => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
            self::PAYE => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::A_PAYER => 'bg-orange-500',
            self::PARTIELLEMENT_PAYE => 'bg-amber-500',
            self::PAYE => 'bg-emerald-500',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
