<?php

namespace App\Enums;

/**
 * Cycle d'une facture fournisseur (ADR 0022) : brouillon → validée (dette constatée) →
 * partiellement payée / payée (lot 4, paiement) ; annulée depuis le brouillon ou une facture
 * validée sans paiement.
 *
 * Une facture validée et pas encore payée s'affiche « Impayée », comme une facture de vente
 * (décision du 10/10/2026). La valeur enregistrée reste `validee` : seul l'affichage change.
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
            self::VALIDEE => 'Impayée',
            self::PARTIELLEMENT_PAYEE => 'Partiellement payée',
            self::PAYEE => 'Payée',
            self::ANNULEE => 'Annulée',
        };
    }

    /**
     * Clé de couleur du point de statut (StatusDot). `validee` est verte partout ailleurs (bon de
     * commande, dépense…) ; ici elle désigne une dette à régler, d'où une clé propre.
     */
    public function statutAffichage(): string
    {
        return $this === self::VALIDEE ? 'facture_impayee' : $this->value;
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
