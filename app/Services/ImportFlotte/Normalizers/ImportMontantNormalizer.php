<?php

namespace App\Services\ImportFlotte\Normalizers;

/**
 * Lit un montant de cellule Excel (GNF) : nombre brut, ou texte avec séparateurs de milliers
 * (espaces, espaces insécables, points) — « 2 500 000 », « 2.500.000 », « 2500000 ». Une virgule est
 * acceptée comme séparateur décimal. Négatif ou illisible : erreur.
 *
 * Le message d'erreur ne reprend jamais la valeur saisie : un montant peut être un salaire, qui
 * ne doit pas apparaître dans un rapport consultable sans la permission dédiée.
 *
 * Retour au format des autres normaliseurs : [montant|null, message d'erreur|null].
 */
class ImportMontantNormalizer
{
    /** @return array{0: ?float, 1: ?string} */
    public static function toMontant(mixed $valeur): array
    {
        if (is_int($valeur) || is_float($valeur)) {
            return $valeur < 0 ? [null, 'montant négatif.'] : [round((float) $valeur, 2), null];
        }

        $brut = trim((string) ($valeur ?? ''));
        if ($brut === '') {
            return [null, null];
        }

        $nettoye = str_replace([' ', "\u{00A0}", "\u{202F}", '.', 'GNF', 'gnf'], '', $brut);
        $nettoye = str_replace(',', '.', $nettoye);

        if (! is_numeric($nettoye)) {
            return [null, 'montant illisible (nombre attendu, ex. 2 500 000).'];
        }

        if ((float) $nettoye < 0) {
            return [null, 'montant négatif.'];
        }

        return [round((float) $nettoye, 2), null];
    }
}
