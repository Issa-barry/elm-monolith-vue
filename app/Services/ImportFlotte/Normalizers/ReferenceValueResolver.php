<?php

namespace App\Services\ImportFlotte\Normalizers;

/**
 * Résout une valeur saisie librement vers un enregistrement de référence
 * (type de véhicule, site...) en tolérant casse/accents/espaces/tirets.
 * Générique et réutilisable par d'autres imports.
 *
 * Ne corrige jamais silencieusement une saisie approximative : matchExact()
 * ne renvoie un résultat que pour une correspondance normalisée unique ;
 * suggestClosest() ne sert qu'à enrichir un message d'erreur, jamais à
 * sélectionner une entité.
 */
class ReferenceValueResolver
{
    /**
     * @param  iterable<object>  $candidates
     * @param  callable(object): string|array<callable(object): string>  $labels  Un ou plusieurs
     *                                                                            champs texte considérés comme équivalents (ex : nom OU code d'un site).
     * @param  array<callable(object): string>  $extraKeys  Clés de recherche numériques
     *                                                      supplémentaires (ex : code site sans zéros initiaux), acceptées
     *                                                      seulement si elles produisent, elles aussi, une correspondance unique.
     */
    public static function matchExact(string $raw, iterable $candidates, callable|array $labels, array $extraKeys = []): ?object
    {
        $labels = is_array($labels) ? $labels : [$labels];
        $target = ImportTextNormalizer::normalize($raw);
        $targetNumeric = self::normalizeNumericCode($raw);

        $matches = [];
        foreach ($candidates as $candidate) {
            if (self::candidateMatches($candidate, $target, $targetNumeric, $labels, $extraKeys)) {
                $matches[spl_object_id($candidate)] = $candidate;
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    /**
     * @param  array<callable(object): string>  $labels
     * @param  array<callable(object): string>  $extraKeys
     */
    private static function candidateMatches(object $candidate, string $target, ?string $targetNumeric, array $labels, array $extraKeys): bool
    {
        foreach ($labels as $label) {
            if (ImportTextNormalizer::normalize((string) $label($candidate)) === $target) {
                return true;
            }
        }

        if ($targetNumeric === null) {
            return false;
        }

        foreach ($extraKeys as $extraKey) {
            if (self::normalizeNumericCode((string) $extraKey($candidate)) === $targetNumeric) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<object>  $candidates
     * @param  callable(object): string  $label
     */
    public static function suggestClosest(string $raw, iterable $candidates, callable $label, int $maxDistance = 2): ?string
    {
        $target = ImportTextNormalizer::normalize($raw);
        if ($target === '') {
            return null;
        }

        $best = null;
        $bestDistance = null;
        $secondBestDistance = null;

        foreach ($candidates as $candidate) {
            $normalizedLabel = ImportTextNormalizer::normalize($label($candidate));
            $distance = levenshtein($target, $normalizedLabel);

            if ($bestDistance === null || $distance < $bestDistance) {
                $secondBestDistance = $bestDistance;
                $bestDistance = $distance;
                $best = $label($candidate);
            } elseif ($secondBestDistance === null || $distance < $secondBestDistance) {
                $secondBestDistance = $distance;
            }
        }

        if ($best === null || $bestDistance === null || $bestDistance === 0 || $bestDistance > $maxDistance) {
            return null;
        }

        // Ambigu seulement si un second candidat est À ÉGALITÉ avec le
        // meilleur (ex: "Tricycle-80" équidistant de "Tricycle-70" et
        // "Tricycle-90") — pas simplement "un peu plus loin" : avec des
        // familles de types proches (Tricycle-70/75/80/90/100...), un
        // candidat strictement plus proche que tous les autres reste une
        // correspondance fiable même si un autre existe à distance+1.
        if ($secondBestDistance !== null && $secondBestDistance === $bestDistance) {
            return null;
        }

        return $best;
    }

    /**
     * Retire les zéros initiaux d'une valeur purement numérique (ex: "01" →
     * "1"), pour permettre une équivalence de code contrôlée — jamais
     * appliqué aux valeurs stockées, seulement à la comparaison.
     */
    public static function normalizeNumericCode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return ltrim($value, '0') ?: '0';
    }
}
