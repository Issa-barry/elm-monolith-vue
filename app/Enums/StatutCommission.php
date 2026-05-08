<?php

namespace App\Enums;

enum StatutCommission: string
{
    case IMPAYE  = 'impaye';
    case PARTIEL = 'partiel';
    case PAYE    = 'paye';

    public function label(): string
    {
        return match ($this) {
            self::IMPAYE  => 'Impayé',
            self::PARTIEL => 'Partiel',
            self::PAYE    => 'Payé',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::IMPAYE  => 'bg-red-500',
            self::PARTIEL => 'bg-amber-500',
            self::PAYE    => 'bg-emerald-500',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::IMPAYE  => 'danger',
            self::PARTIEL => 'warn',
            self::PAYE    => 'success',
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

    public function isPayable(): bool
    {
        return $this !== self::PAYE;
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
