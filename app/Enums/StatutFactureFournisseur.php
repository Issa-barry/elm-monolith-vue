<?php

namespace App\Enums;

/**
 * Cycle d'une facture fournisseur (ADR 0022) : brouillon → validée (dette constatée) →
 * partiellement payée / payée (lot 4, paiement) ; annulée depuis le brouillon ou une facture
 * validée sans paiement.
 */
enum StatutFactureFournisseur: string
{
    case BROUILLON = 'brouillon';
    case VALIDEE = 'validee';
    case PARTIELLEMENT_PAYEE = 'partiellement_payee';
    case PAYEE = 'payee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDEE => 'Validée — à payer',
            self::PARTIELLEMENT_PAYEE => 'Partiellement payée',
            self::PAYEE => 'Payée',
            self::ANNULEE => 'Annulée',
        };
    }

    /** Statuts qui constatent une dette (quantités et montants définitivement facturés). */
    public static function constatees(): array
    {
        return [self::VALIDEE, self::PARTIELLEMENT_PAYEE, self::PAYEE];
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
