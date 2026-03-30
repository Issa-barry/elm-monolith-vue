<?php

namespace App\Enums;

enum PackingStatut: string
{
    case IMPAYEE = 'impayee';
    case PARTIELLE = 'partielle';
    case PAYEE = 'payee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::IMPAYEE => 'Impayée',
            self::PARTIELLE => 'Partielle',
            self::PAYEE => 'Payée',
            self::ANNULEE => 'Annulée',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
