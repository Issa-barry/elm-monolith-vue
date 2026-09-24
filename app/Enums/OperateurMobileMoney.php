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

    /**
     * Étiquette "detail" des lignes compta_mappings "mobile_money:<detail>" (cf.
     * PlanComptableBootstrapService, CompteMappingResolver) — les clés du plan comptable
     * ("orange", "mtn") diffèrent des valeurs de cet enum. Sans compte dédié configuré pour
     * l'étiquette, le resolver retombe sur le Mobile Money générique. AUTRE n'a pas de wallet.
     */
    public function detailComptable(): ?string
    {
        return match ($this) {
            self::ORANGE_MONEY => 'orange',
            self::MOMO => 'mtn',
            self::AUTRE => null,
            default => $this->value,
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
