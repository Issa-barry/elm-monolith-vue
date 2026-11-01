<?php

namespace App\Enums;

/**
 * Statut d'un modèle de contrat (ADR 0044). Seul un modèle actif permet de générer un contrat : la
 * trame fournie par l'application reste en brouillon tant qu'un administrateur ne l'a pas relue et
 * activée.
 */
enum StatutModeleContrat: string
{
    case BROUILLON = 'brouillon';
    case ACTIF = 'actif';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::ACTIF => 'Actif',
        };
    }
}
