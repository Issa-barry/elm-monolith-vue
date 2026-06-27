<?php

namespace App\Enums;

enum AuditEvent: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case VALIDATED = 'validated';
    case CANCELLED = 'cancelled';
    case ENCAISSEMENT_ADDED = 'encaissement_added';
    case ENCAISSEMENT_DELETED = 'encaissement_deleted';
    case DELETED = 'deleted';
    case STOCK_ADJUSTED = 'stock_adjusted';
    case PAID = 'paid';
    case REJECTED = 'rejected';
    case SUBMITTED = 'submitted';
    case EXPORTED = 'exported';
    case PRINTED = 'printed';
    case AUTO_GENERATED = 'auto_generated';
    case AUTO_RECALCULATED = 'auto_recalculated';
    case FRAIS_ADDED = 'frais_added';
    case FRAIS_DELETED = 'frais_deleted';
    case PAYMENT_CANCELLED = 'payment_cancelled';
    case STATUS_CHANGED = 'status_changed';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Créé',
            self::UPDATED => 'Modifié',
            self::VALIDATED => 'Validé',
            self::CANCELLED => 'Annulé',
            self::ENCAISSEMENT_ADDED => 'Encaissement enregistré',
            self::ENCAISSEMENT_DELETED => 'Encaissement supprimé',
            self::DELETED => 'Supprimé',
            self::STOCK_ADJUSTED => 'Stock ajusté',
            self::PAID => 'Paiement enregistré',
            self::REJECTED => 'Rejeté',
            self::SUBMITTED => 'Soumis',
            self::EXPORTED => 'Exporté',
            self::PRINTED => 'Imprimé',
            self::AUTO_GENERATED => 'Génération automatique',
            self::AUTO_RECALCULATED => 'Recalcul automatique',
            self::FRAIS_ADDED => 'Dépense ajoutée',
            self::FRAIS_DELETED => 'Dépense supprimée',
            self::PAYMENT_CANCELLED => 'Paiement annulé',
            self::STATUS_CHANGED => 'Changement de statut',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CREATED => 'blue',
            self::UPDATED => 'amber',
            self::VALIDATED => 'emerald',
            self::CANCELLED => 'red',
            self::ENCAISSEMENT_ADDED => 'violet',
            self::ENCAISSEMENT_DELETED => 'orange',
            self::DELETED => 'red',
            self::STOCK_ADJUSTED => 'teal',
            self::PAID => 'green',
            self::REJECTED => 'red',
            self::SUBMITTED => 'blue',
            self::EXPORTED => 'slate',
            self::PRINTED => 'slate',
            self::AUTO_GENERATED => 'cyan',
            self::AUTO_RECALCULATED => 'cyan',
            self::FRAIS_ADDED => 'orange',
            self::FRAIS_DELETED => 'red',
            self::PAYMENT_CANCELLED => 'red',
            self::STATUS_CHANGED => 'amber',
        };
    }
}
