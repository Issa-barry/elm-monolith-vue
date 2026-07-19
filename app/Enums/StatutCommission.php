<?php

namespace App\Enums;

enum StatutCommission: string
{
    case CREEE = 'creee';
    case IMPAYE = 'impaye';
    case PARTIEL = 'partiel';
    case PAYE = 'paye';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::CREEE => 'Créée',
            self::IMPAYE => 'Impayée',
            self::PARTIEL => 'Partiellement payée',
            self::PAYE => 'Payée',
            self::ANNULEE => 'Annulée',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::CREEE => 'bg-zinc-400',
            self::IMPAYE => 'bg-red-500',
            self::PARTIEL => 'bg-amber-500',
            self::PAYE => 'bg-emerald-500',
            self::ANNULEE => 'bg-zinc-400 dark:bg-zinc-500',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CREEE => 'secondary',
            self::IMPAYE => 'danger',
            self::PARTIEL => 'warn',
            self::PAYE => 'success',
            self::ANNULEE => 'secondary',
        };
    }

    public function isPaye(): bool
    {
        return $this === self::PAYE;
    }

    public function isPartiel(): bool
    {
        return $this === self::PARTIEL;
    }

    public function isAnnulee(): bool
    {
        return $this === self::ANNULEE;
    }

    public function isPayable(): bool
    {
        return ! in_array($this, [self::PAYE, self::ANNULEE], true);
    }

    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
