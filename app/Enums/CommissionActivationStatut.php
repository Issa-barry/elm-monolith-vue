<?php

namespace App\Enums;

/**
 * Statut d'activation partagé par commission_processus et commission_groupes —
 * volontairement le même enum pour les deux, la sémantique (actif/inactif)
 * étant identique.
 */
enum CommissionActivationStatut: string
{
    case ACTIF = 'actif';
    case INACTIF = 'inactif';

    public function label(): string
    {
        return match ($this) {
            self::ACTIF => 'Actif',
            self::INACTIF => 'Inactif',
        };
    }
}
