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

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Commande créée',
            self::UPDATED => 'Commande modifiée',
            self::VALIDATED => 'Commande validée',
            self::CANCELLED => 'Commande annulée',
            self::ENCAISSEMENT_ADDED => 'Encaissement enregistré',
            self::ENCAISSEMENT_DELETED => 'Encaissement supprimé',
            self::DELETED => 'Commande supprimée',
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
        };
    }
}
