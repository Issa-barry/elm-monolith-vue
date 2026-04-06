<?php

namespace App\Enums;

enum StatutCommandeVente: string
{
    case BROUILLON = 'brouillon';
    case EN_COURS = 'en_cours';
    case CLOTUREE = 'cloturee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::EN_COURS => 'En cours',
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
