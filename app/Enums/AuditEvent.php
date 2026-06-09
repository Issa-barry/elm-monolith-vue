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
        };
    }
}
