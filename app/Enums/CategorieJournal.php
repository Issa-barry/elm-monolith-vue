<?php

namespace App\Enums;

enum CategorieJournal: string
{
    case VENTE = 'vente';
    case COMMISSION_VENTE = 'commission_vente';
    case COMMISSION_LOGISTIQUE = 'commission_logistique';
    case SALAIRE = 'salaire';
    case PROPRIETAIRE = 'proprietaire';
    case CASHBACK = 'cashback';
    case DEPENSE_INTERNE = 'depense_interne';
    case AJUSTEMENT = 'ajustement';

    public function label(): string
    {
        return match ($this) {
            self::VENTE => 'Encaissement vente',
            self::COMMISSION_VENTE => 'Commission vente',
            self::COMMISSION_LOGISTIQUE => 'Commission logistique',
            self::SALAIRE => 'Salaire',
            self::PROPRIETAIRE => 'Propriétaire',
            self::CASHBACK => 'Cashback',
            self::DEPENSE_INTERNE => 'Dépense interne',
            self::AJUSTEMENT => 'Ajustement',
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
