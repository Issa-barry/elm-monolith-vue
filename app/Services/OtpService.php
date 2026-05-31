<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OtpService
{
    // TODO: Remplacer par un vrai code généré aléatoirement et envoyé par SMS.
    // Intégrations possibles : Africa's Talking, Twilio, Orange SMS API, etc.
    private const FIXED_CODE = '12345';

    private const TTL_MINUTES = 10;

    private function key(string $telephone): string
    {
        return 'otp:'.md5($telephone);
    }

    private function verifiedKey(string $telephone): string
    {
        return 'otp:verified:'.md5($telephone);
    }

    /**
     * Génère un OTP pour le numéro de téléphone donné et le stocke en cache.
     *
     * TODO: Envoyer le code par SMS via le provider configuré.
     */
    public function generate(string $telephone): string
    {
        // TODO: $code = (string) random_int(10000, 99999);
        $code = self::FIXED_CODE;

        Cache::put($this->key($telephone), $code, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget($this->verifiedKey($telephone));

        return $code;
    }

    /**
     * Vérifie si le code fourni correspond à celui stocké en cache pour ce téléphone.
     */
    public function verify(string $telephone, string $code): bool
    {
        return Cache::get($this->key($telephone)) === $code;
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
    }
}
