<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OtpService
{
    private const TTL_MINUTES = 10;

    /** Au-delà de ce nombre d'essais infructueux, le code est verrouillé : il faut en redemander un nouveau. */
    private const MAX_ATTEMPTS = 5;

    /** Délai minimal entre deux envois de code pour un même numéro (anti-spam). */
    private const RESEND_COOLDOWN_SECONDS = 30;

    private function key(string $telephone): string
    {
        return 'otp:'.md5($telephone);
    }

    private function verifiedKey(string $telephone): string
    {
        return 'otp:verified:'.md5($telephone);
    }

    private function attemptsKey(string $telephone): string
    {
        return 'otp:attempts:'.md5($telephone);
    }

    private function cooldownKey(string $telephone): string
    {
        return 'otp:cooldown:'.md5($telephone);
    }

    /**
     * Génère un OTP aléatoire pour le numéro de téléphone donné et le stocke en cache.
     * Réinitialise le compteur de tentatives et démarre le délai anti-spam de renvoi.
     */
    public function generate(string $telephone): string
    {
        $code = config('otp.fixed_code')
            ?? str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        Cache::put($this->key($telephone), $code, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget($this->verifiedKey($telephone));
        Cache::forget($this->attemptsKey($telephone));
        Cache::put($this->cooldownKey($telephone), true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));

        return $code;
    }

    /**
     * Indique si un nouveau code peut être demandé pour ce numéro (anti-spam : un
     * envoi au maximum toutes les 30 secondes).
     */
    public function canResend(string $telephone): bool
    {
        return ! Cache::has($this->cooldownKey($telephone));
    }

    /**
     * Vérifie si le code fourni correspond à celui stocké en cache pour ce téléphone.
     * Comparaison à temps constant (hash_equals) pour éviter les attaques par timing.
     * Le code est verrouillé après MAX_ATTEMPTS essais infructueux (cf. tooManyAttempts).
     */
    public function verify(string $telephone, string $code): bool
    {
        if ($this->tooManyAttempts($telephone)) {
            return false;
        }

        $stored = Cache::get($this->key($telephone));
        $matches = is_string($stored) && hash_equals($stored, $code);

        if ($matches) {
            Cache::forget($this->attemptsKey($telephone));

            return true;
        }

        $this->recordFailedAttempt($telephone);

        return false;
    }

    /**
     * Indique si le nombre maximal de tentatives infructueuses est atteint pour ce
     * numéro : le code en cours est verrouillé, il faut en redemander un nouveau.
     */
    public function tooManyAttempts(string $telephone): bool
    {
        return (int) Cache::get($this->attemptsKey($telephone), 0) >= self::MAX_ATTEMPTS;
    }

    private function recordFailedAttempt(string $telephone): void
    {
        $key = $this->attemptsKey($telephone);
        $attempts = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Marque l'OTP comme vérifié pour ce numéro (TTL identique).
     */
    public function markVerified(string $telephone): void
    {
        Cache::put($this->verifiedKey($telephone), true, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Indique si l'OTP a été vérifié pour ce numéro.
     */
    public function isVerified(string $telephone): bool
    {
        return (bool) Cache::get($this->verifiedKey($telephone));
    }

    /**
     * Supprime l'OTP du cache (après utilisation).
     */
    public function clear(string $telephone): void
    {
        Cache::forget($this->key($telephone));
        Cache::forget($this->verifiedKey($telephone));
        Cache::forget($this->attemptsKey($telephone));
    }
}
