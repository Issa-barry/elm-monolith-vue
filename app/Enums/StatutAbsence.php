<?php

namespace App\Enums;

/**
 * Statut d'enregistrement d'une absence (docs/rh-absences.md). Pas de circuit de validation en
 * première version : une absence saisie par un utilisateur habilité est enregistrée directement.
 * L'état affiché (à venir, en cours, terminée) se déduit des dates, cf. Absence::etat().
 */
enum StatutAbsence: string
{
    case ENREGISTREE = 'enregistree';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::ENREGISTREE => 'Enregistrée',
            self::ANNULEE => 'Annulée',
        };
    }
}
