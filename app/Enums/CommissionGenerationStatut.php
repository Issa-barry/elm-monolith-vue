<?php

namespace App\Enums;

/**
 * Statut d'une ligne commission_generation_attempts — table append-only,
 * jamais mise à jour. Le statut de génération affiché pour une opération est
 * une vue calculée à partir de la tentative la plus récente (SUCCES = générée,
 * ERREUR = à régulariser), jamais un champ stocké séparément — même idiome que
 * CommissionStatusResolver déjà présent dans le code existant.
 */
enum CommissionGenerationStatut: string
{
    case SUCCES = 'succes';
    case ERREUR = 'erreur';

    public function label(): string
    {
        return match ($this) {
            self::SUCCES => 'Générée',
            self::ERREUR => 'À régulariser',
        };
    }
}
