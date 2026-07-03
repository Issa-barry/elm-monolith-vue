<?php

namespace App\Enums;

enum TypePeriodePaiement: string
{
    case LIVREUR = 'livreur';
    case PROPRIETAIRE = 'proprietaire';
    case SALARIE = 'salarie';

    public function label(): string
    {
        return match ($this) {
            self::LIVREUR => 'Livreurs',
            self::PROPRIETAIRE => 'Propriétaires',
            self::SALARIE => 'Salariés',
        };
    }

    public function periodicity(): string
    {
        return 'quinzaine';
    }

    /** Abréviation utilisée dans la référence auto-générée (ex: PAY-202607-P1-LIV). */
    public function abreviation(): string
    {
        return match ($this) {
            self::LIVREUR => 'LIV',
            self::PROPRIETAIRE => 'PRO',
            self::SALARIE => 'SAL',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
