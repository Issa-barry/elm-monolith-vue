<?php

namespace App\Enums;

enum StatutTransfert: string
{
    case BROUILLON  = 'brouillon';
    case CHARGEMENT = 'chargement';
    case TRANSIT    = 'transit';
    case RECEPTION  = 'reception';
    case CLOTURE    = 'cloture';
    case ANNULE     = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON  => 'Brouillon',
            self::CHARGEMENT => 'En chargement',
            self::TRANSIT    => 'Livraison en cours',
            self::RECEPTION  => 'Réceptionné',
            self::CLOTURE    => 'Clôturé',
            self::ANNULE     => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BROUILLON  => 'secondary',
            self::CHARGEMENT => 'warn',
            self::TRANSIT    => 'primary',
            self::RECEPTION  => 'success',
            self::CLOTURE    => 'success',
            self::ANNULE     => 'danger',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::BROUILLON  => 'bg-zinc-400 dark:bg-zinc-500',
            self::CHARGEMENT => 'bg-amber-400',
            self::TRANSIT    => 'bg-blue-500',
            self::RECEPTION  => 'bg-teal-500',
            self::CLOTURE    => 'bg-emerald-500',
            self::ANNULE     => 'bg-red-400',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }

    /** Seul BROUILLON est encore modifiable */
    public function isEditable(): bool
    {
        return $this === self::BROUILLON;
    }

    /** Statuts terminaux (irréversibles) */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOTURE, self::ANNULE]);
    }
}
