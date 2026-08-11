<?php

namespace App\Services\ImportFlotte\Normalizers;

/**
 * Construit une clé de comparaison tolérante à la casse, aux accents et aux
 * variations d'espacement/ponctuation. N'est utilisé QUE pour rechercher une
 * correspondance — jamais pour remplacer la valeur affichée ou stockée.
 */
class ImportTextNormalizer
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['’', '`'], "'", $value);
        $value = str_replace(['–', '—'], '-', $value);
        // Le tiret est un séparateur optionnel dans nos libellés (ex :
        // "Tricycle-75") : on le retire plutôt que de le normaliser, pour que
        // "Tricycle75" / "Tricycle-75" / "Tricycle 75" soient équivalents.
        $value = preg_replace('/\s*-\s*/', '', $value) ?? $value;
        $value = self::stripAccents($value);
        $value = mb_strtolower($value, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * Table explicite plutôt que iconv(...//TRANSLIT//IGNORE, ...) : ce
     * dernier dépend de la locale système et peut, sur certains environnements
     * (observé sous Windows), supprimer purement le caractère accentué au lieu
     * de le translittérer — cassant silencieusement la comparaison.
     */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'æ' => 'ae', 'œ' => 'oe',
    ];

    private static function stripAccents(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), self::ACCENTS);
    }
}
