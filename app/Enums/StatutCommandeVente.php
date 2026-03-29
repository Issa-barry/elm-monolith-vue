<?php

namespace App\Enums;

enum StatutCommandeVente: string
{
    case EN_COURS = 'en_cours';
    case LIVREE = 'livree';
    case CLOTUREE = 'cloturee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::LIVREE => 'Livrée',
            self::CLOTUREE => 'Clôturée',
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
