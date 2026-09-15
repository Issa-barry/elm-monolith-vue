<?php

namespace App\Enums;

enum OperateurMobileMoney: string
{
    case ORANGE_MONEY = 'orange_money';
    case KULU = 'kulu';
    case SOUTRA_MONEY = 'soutra_money';
    case MOMO = 'momo';
    case PAYCARD = 'paycard';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::ORANGE_MONEY => 'Orange Money',
            self::KULU => 'Kulu',
            self::SOUTRA_MONEY => 'Soutra Money',
            self::MOMO => 'MOMO (MTN Mobile Money)',
            self::PAYCARD => 'PayCard',
            self::AUTRE => 'Autre',
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
