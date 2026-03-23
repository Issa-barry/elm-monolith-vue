<?php

namespace App\Enums;

enum StatutFactureVente: string
{
    case IMPAYEE = 'impayee';
    case PARTIEL = 'partiel';
    case PAYEE   = 'payee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match($this) {
            self::IMPAYEE => 'Impayée',
            self::PARTIEL => 'Partiel',
            self::PAYEE   => 'Payée',
            self::ANNULEE => 'Annulée',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn(self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
