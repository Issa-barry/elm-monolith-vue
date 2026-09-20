<?php

namespace App\Enums;

/**
 * Statut d'un support de trésorerie (caisse, banque, Mobile Money, caisse dédiée à un agent).
 * Dérivé de `valide_le` et `actif` — cf. CompteTresorerie::statut().
 */
enum StatutSupportTresorerie: string
{
    /** Créé, jamais validé : inutilisable partout. */
    case BROUILLON = 'brouillon';
    /** Validé et en service. */
    case ACTIF = 'actif';
    /** Validé puis désactivé. */
    case INACTIF = 'inactif';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::ACTIF => 'Actif',
            self::INACTIF => 'Inactif',
        };
    }
}
