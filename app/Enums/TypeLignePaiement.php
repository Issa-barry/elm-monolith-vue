<?php

namespace App\Enums;

enum TypeLignePaiement: string
{
    case COMMISSION_VENTE = 'commission_vente';
    case COMMISSION_LOGISTIQUE = 'commission_logistique';
    case SALAIRE = 'salaire';
    case PRIME = 'prime';
    case AVANCE = 'avance';
    case DEPENSE = 'depense';
    case RETENUE = 'retenue';
    case AJUSTEMENT = 'ajustement';

    public function label(): string
    {
        return match ($this) {
            self::COMMISSION_VENTE => 'Commission vente',
            self::COMMISSION_LOGISTIQUE => 'Commission logistique',
            self::SALAIRE => 'Salaire de base',
            self::PRIME => 'Prime',
            self::AVANCE => 'Avance',
            self::DEPENSE => 'Dépense déduite',
            self::RETENUE => 'Retenue',
            self::AJUSTEMENT => 'Ajustement',
        };
    }

    public function isDeduction(): bool
    {
        return in_array($this, [self::AVANCE, self::DEPENSE, self::RETENUE], true);
    }

    public function isGain(): bool
    {
        return in_array($this, [self::COMMISSION_VENTE, self::COMMISSION_LOGISTIQUE, self::SALAIRE, self::PRIME], true);
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
