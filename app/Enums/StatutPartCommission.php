<?php

namespace App\Enums;

enum StatutPartCommission: string
{
    /** Commission gagnée mais unlock_at non encore atteint */
    case PENDING = 'pending';

    /** unlock_at atteint, versement autorisé */
    case AVAILABLE = 'available';

    /** Versement partiel effectué */
    case PARTIAL = 'partially_paid';

    /** Entièrement versée */
    case PAID = 'paid';

    /** Annulée (avoir/correction) */
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::AVAILABLE => 'Disponible',
            self::PARTIAL => 'Partiellement versée',
            self::PAID => 'Versée',
            self::CANCELLED => 'Annulée',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-zinc-400 dark:bg-zinc-500',
            self::AVAILABLE => 'bg-amber-500',
            self::PARTIAL => 'bg-blue-500',
            self::PAID => 'bg-emerald-500',
            self::CANCELLED => 'bg-red-400',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public function isPayable(): bool
    {
        return in_array($this, [self::AVAILABLE, self::PARTIAL], true);
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
