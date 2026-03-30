<?php

namespace App\Enums;

enum ModePaiement: string
{
    case ESPECES = 'especes';
    case MOBILE_MONEY = 'mobile_money';
    case VIREMENT = 'virement';
    case CHEQUE = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::ESPECES => 'Espèces',
            self::MOBILE_MONEY => 'Mobile Money',
            self::VIREMENT => 'Virement',
            self::CHEQUE => 'Chèque',
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
