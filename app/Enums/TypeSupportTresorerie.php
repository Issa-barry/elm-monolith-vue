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

    /**
     * Déduit le type de support à partir d'un moyen_paiement de compta_mappings
     * (role=tresorerie) — ex: "especes"/null → Caisse, "mobile_money:orange" →
     * Mobile Money, "virement"/"cheque" → Banque. Retourne null si le libellé
     * n'est pas reconnu (aucune règle codée en dur sur les numéros de compte).
     */
    public static function fromMoyenPaiement(?string $moyenPaiement): ?self
    {
        if ($moyenPaiement === null || $moyenPaiement === 'especes') {
            return self::CAISSE;
        }

        if ($moyenPaiement === 'virement' || $moyenPaiement === 'cheque') {
            return self::BANQUE;
        }

        if ($moyenPaiement === 'mobile_money' || str_starts_with($moyenPaiement, 'mobile_money:')) {
            return self::MOBILE_MONEY;
        }

        return null;
    }
}
