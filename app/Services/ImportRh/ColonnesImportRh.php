<?php

namespace App\Services\ImportRh;

/**
 * Colonnes de la feuille « employes », partagées par l'export pré-rempli et l'analyse : un fichier
 * exporté se réimporte tel quel (mêmes colonnes, même ordre). Seules les colonnes LUES sont prises
 * en compte à l'import ; les colonnes de lecture seule ne sont jamais écrites.
 */
final class ColonnesImportRh
{
    public const FEUILLE = 'employes';

    public const MATRICULE = 'matricule';

    /** Lecture seule : aide à se repérer dans le fichier, jamais écrites. */
    public const LECTURE_SEULE = ['nom', 'prenom', 'agence'];

    public const DATE_EMBAUCHE = 'date_embauche';

    public const SERVICE = 'service';

    public const FONCTION = 'fonction';

    public const CONTRAT_TYPE = 'contrat_type';

    public const CONTRAT_DATE_DEBUT = 'contrat_date_debut';

    public const CONTRAT_DATE_FIN = 'contrat_date_fin';

    /** Seulement avec `rh-remunerations.read` (export, import, aperçu, historique). */
    public const SALAIRE = 'salaire_base';

    public const CONTRAT = [self::CONTRAT_TYPE, self::CONTRAT_DATE_DEBUT, self::CONTRAT_DATE_FIN];

    /** @return list<string> */
    public static function entetes(bool $avecSalaire): array
    {
        return [
            self::MATRICULE,
            ...self::LECTURE_SEULE,
            self::DATE_EMBAUCHE,
            self::SERVICE,
            self::FONCTION,
            ...self::CONTRAT,
            ...($avecSalaire ? [self::SALAIRE] : []),
        ];
    }
}
