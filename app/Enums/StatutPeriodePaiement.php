<?php

namespace App\Enums;

enum StatutPeriodePaiement: string
{
    case BROUILLON = 'brouillon';
    case CALCULEE = 'calculee';
    case VALIDEE = 'validee';
    case CLOTUREE = 'cloturee';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::CALCULEE => 'Calculée',
            self::VALIDEE => 'Validée',
            self::CLOTUREE => 'Clôturée',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::BROUILLON => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
            self::CALCULEE => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
            self::VALIDEE => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
            self::CLOTUREE => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
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
