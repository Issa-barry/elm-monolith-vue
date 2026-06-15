<?php

namespace App\Enums;

enum StatutDepense: string
{
    case BROUILLON = 'brouillon';
    case SOUMIS = 'soumis';
    case VALIDE = 'valide';
    case REJETE = 'rejete';
    case ANNULE = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::SOUMIS => 'Soumis',
            self::VALIDE => 'Validé',
            self::REJETE => 'Rejeté',
            self::ANNULE => 'Annulé',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn ($c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
