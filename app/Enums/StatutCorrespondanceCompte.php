<?php

namespace App\Enums;

/** Résultat de l'analyse d'une fiche pour le rattachement automatique (ADR 0056). */
enum StatutCorrespondanceCompte: string
{
    case DEJA_LIE = 'deja_lie';
    case CERTAINE = 'certaine';
    case AMBIGUE = 'ambigue';
    case AUCUNE = 'aucune';

    public function label(): string
    {
        return match ($this) {
            self::DEJA_LIE => 'Déjà relié',
            self::CERTAINE => 'Correspondance certaine',
            self::AMBIGUE => 'À vérifier par un responsable',
            self::AUCUNE => 'Aucune correspondance',
        };
    }
}
