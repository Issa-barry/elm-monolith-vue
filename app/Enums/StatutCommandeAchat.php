<?php

namespace App\Enums;

enum StatutCommandeAchat: string
{
    case EN_COURS = 'en_cours';
    case RECEPTIONNEE = 'receptionnee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::RECEPTIONNEE => 'Réceptionnée',
            self::ANNULEE => 'Annulée',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
