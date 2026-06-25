<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CommissionSearchService
{
    /**
     * Lowercase + strip combining diacritics (accents).
     * "impayé" → "impaye", "Éléonore" → "eleonore".
     */
    public static function normalizeText(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');

        if (class_exists('Normalizer')) {
            $nfd = \Normalizer::normalize($lower, \Normalizer::NFD);
            if ($nfd !== false) {
                return preg_replace('/[\x{0300}-\x{036f}]/u', '', $nfd) ?? $lower;
            }
        }

        // Fallback when intl extension is absent
        return strtr($lower, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /**
     * Keep only digit characters.
     * "+224 622 000 012" → "224622000012".
     */
    public static function normalizePhoneDigits(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    /**
     * Parse a user-typed amount string to an integer, or null if it is not a number.
     * Handles: "4800", "4 800", "4 800 GNF", "4800gnf", "4,800".
     */
    public static function parseAmountQuery(string $query): ?int
    {
        $clean = preg_replace('/[\s,]+/', '', str_ireplace('gnf', '', $query));
        if ($clean !== null && $clean !== '' && ctype_digit($clean)) {
            return (int) $clean;
        }

        return null;
    }

    /**
     * Returns true when ALL whitespace-separated tokens in $search match $livreur on
     * at least one field (name, vehicle, phone, amount, or statut keyword).
     * Multi-word search uses AND logic across tokens, OR across fields.
     *
     * @param  array{nom:string,telephone:?string,vehicules:array<int, array{nom:string,immatriculation:?string}>|string|null,impaye:float,paye:float}  $livreur
     */
    public static function matches(array $livreur, string $search): bool
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($search)) ?: []));

        foreach ($tokens as $token) {
            if (! self::tokenMatchesLivreur($livreur, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter a collection of livreur rows by a free-text search string.
     * Empty / whitespace-only search returns the collection unchanged.
     */
    public static function filter(Collection $livreurs, string $search): Collection
    {
        $search = trim($search);
        if ($search === '') {
            return $livreurs;
        }

        return $livreurs->filter(fn ($l) => self::matches($l, $search))->values();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function tokenMatchesLivreur(array $l, string $token): bool
    {
        $normToken = self::normalizeText($token);

        // "GNF" est un suffixe monétaire neutre : ne bloque pas le AND
        return $normToken === 'gnf'
            || self::matchesText((string) ($l['nom'] ?? ''), $normToken)
            || self::matchesText(self::vehiculesText($l['vehicules'] ?? []), $normToken)
            || self::matchesPhone($l, $token)
            || self::matchesAmount($l, $token)
            || self::matchesStatut($l, $normToken);
    }

    /**
     * Accepte le nouveau format structuré (tableau de {nom, immatriculation})
     * ainsi que l'ancien format texte (legacy, encore utilisé par certains
     * contrôleurs non migrés) pour rester compatible avec tous les appelants.
     *
     * @param  array<int, array{nom:string,immatriculation:?string}>|string|null  $vehicules
     */
    private static function vehiculesText(array|string|null $vehicules): string
    {
        if (! is_array($vehicules)) {
            return (string) $vehicules;
        }

        return implode(' ', array_map(
            fn ($v) => trim(($v['nom'] ?? '').' '.($v['immatriculation'] ?? '')),
            $vehicules
        ));
    }

    private static function matchesText(string $haystack, string $normToken): bool
    {
        return str_contains(self::normalizeText($haystack), $normToken);
    }

    /**
     * Phone match: only when the token contains no alphabetic chars (guards against
     * immatriculations like "RC-001" accidentally extracting "001").
     * Min 3 digits supports "issa 622" and "224 622 000 012" split tokens.
     */
    private static function matchesPhone(array $l, string $token): bool
    {
        if (preg_match('/[a-zA-ZÀ-ÿ]/u', $token)) {
            return false;
        }
        $queryDigits = self::normalizePhoneDigits($token);
        if (strlen($queryDigits) < 3) {
            return false;
        }
        $telDigits = self::normalizePhoneDigits((string) ($l['telephone'] ?? ''));

        return $telDigits !== '' && str_contains($telDigits, $queryDigits);
    }

    /** Amount match: "4800", "4 800", "4 800 GNF", "4800gnf" against impayé/payé/total. */
    private static function matchesAmount(array $l, string $token): bool
    {
        $amount = self::parseAmountQuery($token);
        if ($amount === null) {
            return false;
        }
        $impaye = (int) round((float) ($l['impaye'] ?? 0));
        $paye = (int) round((float) ($l['paye'] ?? 0));

        foreach ([$impaye, $paye, $impaye + $paye] as $amt) {
            if (str_contains((string) $amt, (string) $amount)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Statut keyword match (normalized: "impayé"→"impaye", "payé"→"paye").
     * "paye"    → entièrement réglé (impaye ≤ 0).
     * "impaye"  → solde restant (impaye > 0).
     * "partiel" → a payé ET a encore un reste.
     */
    private static function matchesStatut(array $l, string $normToken): bool
    {
        $impaye = (float) ($l['impaye'] ?? 0);
        $paye = (float) ($l['paye'] ?? 0);

        return match ($normToken) {
            'impaye' => $impaye > 0,
            'paye' => $paye > 0 && $impaye <= 0,
            'partiel' => $impaye > 0 && $paye > 0,
            default => false,
        };
    }
}
