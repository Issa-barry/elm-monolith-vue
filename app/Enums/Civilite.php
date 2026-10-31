<?php

namespace App\Enums;

/**
 * Civilité d'une personne (dossier salarié, docs/rh-employes-identite.md). Remplace la saisie du
 * sexe : la colonne `personnes.sexe` reste alimentée, déduite de la civilité, par compatibilité.
 */
enum Civilite: string
{
    case MONSIEUR = 'monsieur';
    case MADAME = 'madame';
    case MADEMOISELLE = 'mademoiselle';

    public function label(): string
    {
        return match ($this) {
            self::MONSIEUR => 'Monsieur',
            self::MADAME => 'Madame',
            self::MADEMOISELLE => 'Mademoiselle',
        };
    }

    /** Valeur de l'ancienne colonne `personnes.sexe` (M/F). */
    public function sexe(): string
    {
        return $this === self::MONSIEUR ? 'M' : 'F';
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn ($c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
