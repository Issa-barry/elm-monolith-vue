<?php

namespace App\Enums;

enum StatutAnomalieEncaissement: string
{
    case NOUVELLE = 'nouvelle';
    case EN_COURS = 'en_cours';
    case RESOLUE = 'resolue';
    case FAUSSE_ALERTE = 'fausse_alerte';

    public function label(): string
    {
        return match ($this) {
            self::NOUVELLE => 'Nouvelle',
            self::EN_COURS => "En cours d'analyse",
            self::RESOLUE => 'Résolue',
            self::FAUSSE_ALERTE => 'Fausse alerte',
        };
    }

    public function estOuverte(): bool
    {
        return $this === self::NOUVELLE || $this === self::EN_COURS;
    }
}
