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

    /**
     * Inverse de detailComptable() : l'opérateur dont le wallet est désigné par ce détail de
     * compta_mappings ("orange" → Orange Money), null si aucun opérateur ne lui correspond (ex:
     * "djomy", wallet d'exemple du plan comptable sans opérateur saisissable).
     */
    public static function fromDetailComptable(string $detail): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->detailComptable() === $detail) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Opérateurs pouvant porter un support de trésorerie (un wallet réel, donc un compte à part) —
     * AUTRE n'en a jamais : un encaissement ne peut plus être rattaché à un opérateur indéterminé.
     *
     * @return list<self>
     */
    public static function avecWallet(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case !== self::AUTRE));
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }

    /** Options proposées à la création d'un support Mobile Money (cf. avecWallet()). */
    public static function optionsAvecWallet(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::avecWallet()
        );
    }
}
