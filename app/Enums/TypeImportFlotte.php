<?php

namespace App\Enums;

/**
 * Deux modes d'import flotte, cf. ImportFlotteParser :
 * - FLOTTE : fichier à deux feuilles ("vehicules" + "livreurs"), pour créer
 *   une flotte neuve (propriétaire + véhicule + équipe + livreurs) en une
 *   fois. Peut aussi rattacher des livreurs à un véhicule déjà existant, mais
 *   exige alors de reporter toute la ligne "vehicules" comme simple ancrage.
 * - LIVREURS : fichier à une seule feuille ("livreurs"), pour ajouter/mettre
 *   à jour des livreurs sur des véhicules déjà en base — cas d'usage courant
 *   une fois la flotte initiale importée. Chaque immatriculation doit déjà
 *   exister ; aucun véhicule/propriétaire n'est jamais créé dans ce mode.
 */
enum TypeImportFlotte: string
{
    case FLOTTE = 'flotte';
    case LIVREURS = 'livreurs';

    public function label(): string
    {
        return match ($this) {
            self::FLOTTE => 'Propriétaires, véhicules et livreurs',
            self::LIVREURS => 'Livreurs (véhicules déjà existants)',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
