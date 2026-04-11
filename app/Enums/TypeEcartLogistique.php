<?php

namespace App\Enums;

enum TypeEcartLogistique: string
{
    case CONFORME = 'conforme';
    case CASSE    = 'casse';
    case PERTE    = 'perte';
    case SURPLUS  = 'surplus';
    case MANQUANT = 'manquant';

    public function label(): string
    {
        return match ($this) {
            self::CONFORME => 'Conforme',
            self::CASSE    => 'Casse',
            self::PERTE    => 'Perte',
            self::SURPLUS  => 'Surplus',
            self::MANQUANT => 'Manquant',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::CONFORME => 'bg-emerald-500',
            self::CASSE    => 'bg-red-500',
            self::PERTE    => 'bg-red-500',
            self::SURPLUS  => 'bg-amber-500',
            self::MANQUANT => 'bg-amber-500',
        };
    }

    public function isProblematique(): bool
    {
        return in_array($this, [self::CASSE, self::PERTE, self::MANQUANT]);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
