<?php

namespace App\Models\Concerns;

/**
 * Normalise un libellé saisi librement (nom de catégorie, d'option, de valeur…) :
 * espaces superflus compactés, première lettre mise en majuscule. Le reste de la
 * chaîne est laissé tel quel (pas de mise en minuscule forcée) pour ne pas casser
 * les sigles/abréviations légitimes (tailles "XL", "XXL", "3XL"...). Évite les
 * doublons visuels ("noir" / "Noir") dans les listes de sélection et le catalogue
 * d'options réutilisables.
 */
trait NormalizesLabel
{
    protected static function normalizeLabel(mixed $value): mixed
    {
        if ($value === null || trim((string) $value) === '') {
            return $value;
        }

        $v = trim(preg_replace('/\s+/u', ' ', $value));

        return mb_strtoupper(mb_substr($v, 0, 1)).mb_substr($v, 1);
    }
}
