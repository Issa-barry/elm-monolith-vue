<?php

namespace App\Enums;

/**
 * Pièce remise par le fournisseur pour un achat (décision du 10/10/2026) : certains fournisseurs ne
 * remettent aucune facture, ou un document sans numéro. Le numéro du fournisseur est facultatif dans
 * tous les cas ; sans document, il doit rester vide.
 */
enum TypeJustificatifAchat: string
{
    case FACTURE = 'facture';
    case RECU = 'recu';
    case TICKET = 'ticket';
    case AUCUN = 'aucun';

    public function label(): string
    {
        return match ($this) {
            self::FACTURE => 'Facture',
            self::RECU => 'Reçu',
            self::TICKET => 'Ticket',
            self::AUCUN => 'Aucun document',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
