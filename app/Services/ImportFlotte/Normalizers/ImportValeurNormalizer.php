<?php

namespace App\Services\ImportFlotte\Normalizers;

/**
 * Interprétation de valeurs de cellule libres communes aux imports Excel (booléens
 * oui/non, entiers positifs bornés) — extrait d'ImportFlotteParser (toBool/toUsageBool/
 * toCapaciteOrNull) pour être réutilisé tel quel par ImportVehiculesMajParser sans
 * dupliquer cette logique ni faire dépendre un parseur de l'autre. Comportement
 * strictement identique à l'original : ImportFlotteParser délègue désormais ici.
 */
class ImportValeurNormalizer
{
    /**
     * @param  mixed  $valeur  cellule brute — vide/absente, une chaîne ("oui"/"1"/...), ou
     *                         un booléen PHP natif si PhpSpreadsheet a interprété la cellule
     *                         Excel comme telle (case à cocher / type booléen de la feuille).
     */
    public static function toBool(mixed $valeur): ?bool
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        // PhpSpreadsheet peut retourner un booléen PHP natif pour une cellule Excel de
        // type booléen (formatData désactivé ou cellule sans format d'affichage) — sans ce
        // cas, (string) false ci-dessous donnerait "" et ferait passer un "non"/"faux"
        // légitime pour une valeur non reconnue.
        if (is_bool($valeur)) {
            return $valeur;
        }
        $v = ImportTextNormalizer::normalize((string) $valeur);

        return match ($v) {
            'oui', 'true', '1', 'vrai', 'yes', 'x' => true,
            'non', 'false', '0', 'faux', 'no' => false,
            default => null,
        };
    }

    /**
     * @param  bool  $valeurParDefaut  appliquée si la cellule est vide/absente — jamais
     *                                 utilisée quand une valeur est présente mais non reconnue
     *                                 (cf. $erreur en retour dans ce cas).
     * @return array{0: bool, 1: string|null} valeur et message d'erreur — jamais les deux à
     *                                        la fois.
     */
    public static function toBoolAvecDefaut(mixed $valeur, bool $valeurParDefaut): array
    {
        if ($valeur === null || $valeur === '') {
            return [$valeurParDefaut, null];
        }

        $bool = self::toBool($valeur);
        if ($bool === null) {
            return [$valeurParDefaut, sprintf(
                '"%s" non reconnu (attendu : oui/non, yes/no, 1/0, true/false).',
                trim((string) $valeur)
            )];
        }

        return [$bool, null];
    }

    /**
     * @return array{0: int|null, 1: string|null} valeur et message d'erreur — jamais les deux à
     *                                            la fois. Cellule vide = pas de valeur saisie (pas
     *                                            une erreur), distinct d'une valeur invalide.
     */
    public static function toEntierOuNull(mixed $valeur, int $min = 1, int $max = 99999): array
    {
        $brut = trim((string) ($valeur ?? ''));
        if ($brut === '') {
            return [null, null];
        }
        if (! is_numeric($brut) || (int) $brut != $brut || (int) $brut < $min || (int) $brut > $max) {
            return [null, "\"{$brut}\" (entier entre {$min} et {$max} attendu)."];
        }

        return [(int) $brut, null];
    }
}
