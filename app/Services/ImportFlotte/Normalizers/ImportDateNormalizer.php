<?php

namespace App\Services\ImportFlotte\Normalizers;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Lit une date de cellule Excel, quelle que soit sa forme : date Excel (numéro de série, quand la
 * feuille est lue en valeurs brutes), texte JJ/MM/AAAA (format conseillé), JJ-MM-AAAA ou
 * AAAA-MM-JJ. Une valeur non vide mais illisible est une erreur, jamais une date devinée.
 *
 * Retour au format des autres normaliseurs : [valeur AAAA-MM-JJ|null, message d'erreur|null].
 */
class ImportDateNormalizer
{
    /** @return array{0: ?string, 1: ?string} */
    public static function toDate(mixed $valeur): array
    {
        if ($valeur instanceof \DateTimeInterface) {
            return [Carbon::instance($valeur)->toDateString(), null];
        }

        $brut = trim((string) ($valeur ?? ''));
        if ($brut === '') {
            return [null, null];
        }

        // Numéro de série Excel (jours depuis 1900) : bornes larges, de 1900 à 2200.
        if (is_numeric($brut) && (float) $brut >= 1 && (float) $brut < 110000) {
            try {
                return [Carbon::instance(ExcelDate::excelToDateTimeObject((float) $brut))->toDateString(), null];
            } catch (Throwable) {
                return [null, "\"{$brut}\" n'est pas une date valide (format attendu : JJ/MM/AAAA)."];
            }
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $brut);
            $erreurs = \DateTime::getLastErrors();
            if ($date !== false && ($erreurs === false || ($erreurs['warning_count'] === 0 && $erreurs['error_count'] === 0))) {
                return [$date->format('Y-m-d'), null];
            }
        }

        return [null, "\"{$brut}\" n'est pas une date valide (format attendu : JJ/MM/AAAA)."];
    }
}
