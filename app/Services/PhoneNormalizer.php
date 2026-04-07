<?php

namespace App\Services;

class PhoneNormalizer
{
    /**
     * Normalize a phone number to E.164 format.
     *
     * Accepts numbers with or without spaces/dashes as long as they start with "+".
     * Examples:
     *   "+33 758 855 039"  → "+33758855039"
     *   "+224-622-176-056" → "+224622176056"
     *   "+33758855039"     → "+33758855039"  (no change)
     *
     * Returns null if the number is clearly invalid (empty, no "+", too short/long).
     */
    public static function normalize(string $phone): ?string
    {
        $phone = trim($phone);

        if ($phone === '' || ! str_starts_with($phone, '+')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (! $digits || strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * Returns true if the string is a valid E.164 number (+ followed by 7–15 digits).
     */
    public static function isE164(string $phone): bool
    {
        return (bool) preg_match('/^\+\d{7,15}$/', trim($phone));
    }
}
