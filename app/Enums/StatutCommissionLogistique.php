<?php

namespace App\Enums;

enum StatutCommissionLogistique: string
{
    case EN_ATTENTE           = 'en_attente';
    case PARTIELLEMENT_VERSEE = 'partiellement_versee';
    case VERSEE               = 'versee';
    case ANNULEE              = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE           => 'En attente',
            self::PARTIELLEMENT_VERSEE => 'Partiellement versée',
            self::VERSEE               => 'Versée',
            self::ANNULEE              => 'Annulée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EN_ATTENTE           => 'danger',
            self::PARTIELLEMENT_VERSEE => 'warn',
            self::VERSEE               => 'success',
            self::ANNULEE              => 'secondary',
        };
    }

    /** Classe CSS Tailwind pour StatusDot (cohérent avec CommissionVente) */
    public function dotClass(): string
    {
        return match ($this) {
            self::EN_ATTENTE           => 'bg-red-500',
            self::PARTIELLEMENT_VERSEE => 'bg-amber-500',
            self::VERSEE               => 'bg-emerald-500',
            self::ANNULEE              => 'bg-zinc-400 dark:bg-zinc-500',
        };
    }

    public function isVersee(): bool
    {
        return $this === self::VERSEE;
    }

    public function isAnnulee(): bool
    {
        return $this === self::ANNULEE;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
