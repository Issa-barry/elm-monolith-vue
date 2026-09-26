<?php

namespace App\Enums;

/**
 * Statut d'une ligne commission_generation_attempts — table append-only,
 * jamais mise à jour. Le statut de génération affiché pour une opération est
 * une vue calculée à partir de la tentative la plus récente (SUCCES = générée,
 * PARTIEL = certaines cibles générées mais au moins une à régulariser, ERREUR
 * = aucune cible générée), jamais un champ stocké séparément — même idiome
 * que CommissionStatusResolver déjà présent dans le code existant.
 *
 * PARTIEL ajouté le 05/09/2026 (chantier 2A, indépendance des cibles de
 * commission) : depuis ce chantier, une cible dont le bénéficiaire est
 * introuvable (ex: consultant non désigné) n'annule plus les cibles
 * correctement résolues de la même opération (cf.
 * CommissionEnveloppeGenerator::genererDepuisContexte()) — l'ancien statut
 * ERREUR ne suffisait plus à distinguer "rien n'a été généré" de "une partie
 * a été générée, le reste est à régulariser". Ajout purement additif : la
 * colonne `statut` reste un simple varchar, aucune migration nécessaire.
 */
enum CommissionGenerationStatut: string
{
    case SUCCES = 'succes';
    case PARTIEL = 'partiel';
    case ERREUR = 'erreur';

    public function label(): string
    {
        return match ($this) {
            self::SUCCES => 'Générée',
            self::PARTIEL => 'Partiellement générée — à régulariser',
            self::ERREUR => 'À régulariser',
        };
    }
}
