<?php

namespace App\Enums;

enum StatutTransfert: string
{
    case BROUILLON = 'brouillon';
    case PREPARATION = 'preparation';
    case CHARGEMENT = 'chargement';
    case TRANSIT = 'transit';
    case RECEPTION = 'reception';
    case CLOTURE = 'cloture';
    case ANNULE = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::PREPARATION => 'En préparation',
            self::CHARGEMENT => 'Chargement',
            self::TRANSIT => 'En transit',
            self::RECEPTION => 'En réception',
            self::CLOTURE => 'Clôturé',
            self::ANNULE => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BROUILLON => 'secondary',
            self::PREPARATION => 'info',
            self::CHARGEMENT => 'warn',
            self::TRANSIT => 'primary',
            self::RECEPTION => 'warn',
            self::CLOTURE => 'success',
            self::ANNULE => 'danger',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }

    /** Statuts qui permettent encore des modifications */
    public function isEditable(): bool
    {
        return in_array($this, [self::BROUILLON, self::PREPARATION]);
    }

    /** Statuts qui indiquent que le transfert est terminé */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOTURE, self::ANNULE]);
    }
}
