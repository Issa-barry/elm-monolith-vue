<?php

namespace App\Services\ImportProduits;

/**
 * Protection contre l'injection de formule (CSV/Excel injection) : toute valeur texte libre
 * saisie par un utilisateur (nom, description...) et réinjectée dans un export — notamment le
 * fichier de reprise, qui réaffiche des données déjà en base — est neutralisée si elle commence
 * par un caractère qu'Excel/LibreOffice interprète comme déclencheur de formule.
 */
class ImportProduitsCellSanitizer
{
    private const DECLENCHEURS = ['=', '+', '-', '@'];

    public static function neutraliser(?string $valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return $valeur;
        }

        return in_array($valeur[0], self::DECLENCHEURS, true) ? "'".$valeur : $valeur;
    }
}
