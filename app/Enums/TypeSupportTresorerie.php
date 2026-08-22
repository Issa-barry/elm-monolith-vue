<?php

namespace App\Enums;

enum TypeSupportTresorerie: string
{
    case CAISSE = 'caisse';
    case BANQUE = 'banque';
    case MOBILE_MONEY = 'mobile_money';

    public function label(): string
    {
        return match ($this) {
            self::CAISSE => 'Caisse',
            self::BANQUE => 'Banque',
            self::MOBILE_MONEY => 'Mobile Money',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
