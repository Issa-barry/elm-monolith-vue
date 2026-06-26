<?php

namespace App\Support;

/**
 * Réplique côté serveur (PHP/Blade) le formatage d'affichage des numéros de
 * téléphone fait côté client par `formatPhoneDisplay()` (resources/js/lib/utils.ts).
 * Nécessaire pour les exports PDF/impression qui n'exécutent pas de JS.
 */
class PhoneFormatter
{
    /**
     * @var array<int, array{code: string, byLength: array<int, array<int, int>>}>
     */
    private const RULES = [
        ['code' => '224', 'byLength' => [9 => [3, 2, 2, 2]]], // Guinée
        ['code' => '245', 'byLength' => [7 => [3, 2, 2]]], // Guinée-Bissau
        ['code' => '221', 'byLength' => [9 => [2, 3, 2, 2]]], // Sénégal
        ['code' => '223', 'byLength' => [8 => [2, 2, 2, 2]]], // Mali
        ['code' => '225', 'byLength' => [10 => [2, 2, 2, 2, 2]]], // Côte d'Ivoire
        ['code' => '231', 'byLength' => [8 => [2, 3, 3]]], // Liberia
        ['code' => '232', 'byLength' => [8 => [2, 3, 3]]], // Sierra Leone
        ['code' => '971', 'byLength' => [9 => [2, 3, 4]]], // Émirats arabes unis
        ['code' => '33', 'byLength' => [9 => [1, 2, 2, 2, 2], 10 => [2, 2, 2, 2, 2]]], // France
        ['code' => '86', 'byLength' => [11 => [3, 4, 4]]], // Chine
        ['code' => '91', 'byLength' => [10 => [5, 5]]], // Inde
    ];

    /** @var array<int, array<int, int>> */
    private const COMMON_PATTERNS = [
        7 => [3, 2, 2],
        8 => [2, 2, 2, 2],
        9 => [3, 2, 2, 2],
        10 => [2, 2, 2, 2, 2],
        11 => [3, 4, 4],
    ];

    public static function display(?string $value): string
    {
        if (! $value || trim($value) === '') {
            return '—';
        }

        $raw = trim($value);
        $normalized = str_starts_with($raw, '00') ? '+'.substr($raw, 2) : $raw;
        $hasPlusPrefix = str_starts_with($normalized, '+');
        $digits = preg_replace('/\D/', '', $normalized);

        if (! $digits) {
            return $raw;
        }

        if ($hasPlusPrefix) {
            $rule = self::detectRule($digits);
            if (! $rule) {
                return '+'.self::group($digits);
            }

            $localDigits = substr($digits, strlen($rule['code']));
            if ($localDigits === '' || $localDigits === false) {
                return '+'.$rule['code'];
            }

            return '+'.$rule['code'].' '.self::formatLocal($localDigits, $rule);
        }

        return self::formatLocal($digits);
    }

    /** @param  array{code: string, byLength: array<int, array<int, int>>}|null  $rule */
    private static function formatLocal(string $digits, ?array $rule = null): string
    {
        $pattern = $rule['byLength'][strlen($digits)] ?? self::COMMON_PATTERNS[strlen($digits)] ?? null;

        return self::group($digits, $pattern);
    }

    /** @param  array<int, int>|null  $pattern */
    private static function group(string $digits, ?array $pattern = null): string
    {
        if ($digits === '') {
            return '';
        }

        if (! $pattern || count($pattern) === 0) {
            return trim((string) preg_replace('/(\d{3})(?=\d)/', '$1 ', $digits));
        }

        $chunks = [];
        $cursor = 0;
        foreach ($pattern as $size) {
            if ($cursor >= strlen($digits)) {
                break;
            }
            $chunks[] = substr($digits, $cursor, $size);
            $cursor += $size;
        }

        if ($cursor < strlen($digits)) {
            $chunks[] = substr($digits, $cursor);
        }

        return implode(' ', array_filter($chunks, fn ($c) => $c !== ''));
    }

    /** @return array{code: string, byLength: array<int, array<int, int>>}|null */
    private static function detectRule(string $digits): ?array
    {
        foreach (self::RULES as $rule) {
            if (str_starts_with($digits, $rule['code'])) {
                return $rule;
            }
        }

        return null;
    }
}
