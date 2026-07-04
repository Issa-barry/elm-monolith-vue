<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OtpService
{
    private const TTL_MINUTES = 10;

    /** Au-delà de ce nombre d'essais infructueux, le code est verrouillé (et supprimé) : il faut en redemander un nouveau. */
    private const MAX_ATTEMPTS = 5;

    /** Délai minimal entre deux envois de code pour un même numéro (anti-spam). */
    private const RESEND_COOLDOWN_SECONDS = 30;

    private const MAX_SENDS_PER_HOUR = 5;

    private const MAX_SENDS_PER_DAY = 10;

    /**
     * Construit une clé de cache pour ce numéro, optionnellement liée à un contexte
     * (ex: l'identifiant d'une invitation) pour qu'un même téléphone ne partage pas
     * le même code d'un contexte à l'autre.
     */
    private function cacheKey(string $prefix, string $telephone, ?string $context): string
    {
        $identity = $context !== null ? $telephone.'|'.$context : $telephone;

        return $prefix.':'.md5($identity);
    }

    private function key(string $telephone, ?string $context): string
    {
        return $this->cacheKey('otp', $telephone, $context);
    }

    private function verifiedKey(string $telephone, ?string $context): string
    {
        return $this->cacheKey('otp:verified', $telephone, $context);
    }

    private function attemptsKey(string $telephone, ?string $context): string
    {
        return $this->cacheKey('otp:attempts', $telephone, $context);
    }

    private function cooldownKey(string $telephone, ?string $context): string
    {
        return $this->cacheKey('otp:cooldown', $telephone, $context);
    }

    private function hourlyKey(string $telephone, ?string $context): string
    {
        return $this->cacheKey('otp:hourly', $telephone, $context);
    }

    private function dailyKey(string $telephone, ?string $context): string
    {
        return $this->cacheKey('otp:daily', $telephone, $context);
    }

    /**
     * Génère un OTP à 6 chiffres pour le numéro de téléphone donné et le stocke en
     * cache. Réinitialise le compteur de tentatives, démarre le délai anti-spam de
     * renvoi et incrémente les compteurs horaire/journalier.
     *
     * @param  string|null  $context  Lie le code à un contexte précis (ex: id d'invitation) pour éviter qu'un code généré pour un contexte soit valide dans un autre.
     */
    public function generate(string $telephone, ?string $context = null): string
    {
        $code = config('otp.fixed_code')
            ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($telephone, $context), $code, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget($this->verifiedKey($telephone, $context));
        Cache::forget($this->attemptsKey($telephone, $context));

        $cooldownUntil = now()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
        Cache::put($this->cooldownKey($telephone, $context), $cooldownUntil->timestamp, $cooldownUntil);

        $this->incrementWindow($this->hourlyKey($telephone, $context), 3600);
        $this->incrementWindow($this->dailyKey($telephone, $context), 86400);

        Log::info('otp.sent', ['telephone' => self::mask($telephone), 'context' => $context]);

        return $code;
    }

    /** Nombre de secondes d'attente avant de pouvoir redemander un code (0 = autorisé). */
    public function resendCooldownSeconds(): int
    {
        return self::RESEND_COOLDOWN_SECONDS;
    }

    /**
     * Indique si un nouveau code peut être demandé pour ce numéro : ni avant le
     * délai anti-spam (30s), ni au-delà des plafonds horaire/journalier.
     */
    public function canSend(string $telephone, ?string $context = null): bool
    {
        return $this->resendWaitSeconds($telephone, $context) === 0;
    }

    /**
     * Secondes à attendre avant qu'un nouvel envoi soit autorisé (0 si immédiat).
     * Retient le motif de blocage dont l'échéance est la plus lointaine parmi le
     * délai anti-spam et les plafonds horaire/journalier : peu importe la cause
     * exacte, on ne révèle que le temps d'attente au client (aucune fuite d'info).
     */
    public function resendWaitSeconds(string $telephone, ?string $context = null): int
    {
        $now = now()->timestamp;
        $waits = [];

        $cooldownUntil = Cache::get($this->cooldownKey($telephone, $context));
        if (is_int($cooldownUntil)) {
            $waits[] = $cooldownUntil - $now;
        }

        if ($this->windowCount($this->hourlyKey($telephone, $context)) >= self::MAX_SENDS_PER_HOUR) {
            $waits[] = ($this->windowExpiresAt($this->hourlyKey($telephone, $context)) ?? $now) - $now;
        }

        if ($this->windowCount($this->dailyKey($telephone, $context)) >= self::MAX_SENDS_PER_DAY) {
            $waits[] = ($this->windowExpiresAt($this->dailyKey($telephone, $context)) ?? $now) - $now;
        }

        return $waits === [] ? 0 : max(0, max($waits));
    }

    /**
     * Vérifie si le code fourni correspond à celui stocké en cache pour ce téléphone.
     * Comparaison à temps constant (hash_equals) pour éviter les attaques par timing.
     * Le code est verrouillé et supprimé après MAX_ATTEMPTS essais infructueux, et
     * supprimé également après un succès (usage unique).
     */
    public function verify(string $telephone, string $code, ?string $context = null): bool
    {
        if ($this->tooManyAttempts($telephone, $context)) {
            return false;
        }

        $stored = Cache::get($this->key($telephone, $context));
        $matches = is_string($stored) && hash_equals($stored, $code);

        if ($matches) {
            Cache::forget($this->key($telephone, $context));
            Cache::forget($this->attemptsKey($telephone, $context));
            Log::info('otp.validated', ['telephone' => self::mask($telephone), 'context' => $context]);

            return true;
        }

        $this->recordFailedAttempt($telephone, $context);

        return false;
    }

    /**
     * Indique si le nombre maximal de tentatives infructueuses est atteint pour ce
     * numéro : le code en cours est verrouillé, il faut en redemander un nouveau.
     */
    public function tooManyAttempts(string $telephone, ?string $context = null): bool
    {
        return (int) Cache::get($this->attemptsKey($telephone, $context), 0) >= self::MAX_ATTEMPTS;
    }

    /**
     * Indique si un code est encore actif en cache pour ce numéro (ni expiré par
     * TTL naturel, ni jamais généré). Utilisé pour distinguer "code expiré" de
     * "code incorrect" côté contrôleur.
     */
    public function hasActiveCode(string $telephone, ?string $context = null): bool
    {
        return Cache::has($this->key($telephone, $context));
    }

    private function recordFailedAttempt(string $telephone, ?string $context): void
    {
        $key = $this->attemptsKey($telephone, $context);
        $attempts = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes(self::TTL_MINUTES));

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Verrouillage : le code est supprimé, un nouveau devra être redemandé.
            Cache::forget($this->key($telephone, $context));
            Log::warning('otp.blocked', ['telephone' => self::mask($telephone), 'context' => $context]);
        } else {
            Log::info('otp.incorrect', ['telephone' => self::mask($telephone), 'context' => $context, 'attempts' => $attempts]);
        }
    }

    /**
     * Marque l'OTP comme vérifié pour ce numéro (TTL identique).
     */
    public function markVerified(string $telephone, ?string $context = null): void
    {
        Cache::put($this->verifiedKey($telephone, $context), true, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Indique si l'OTP a été vérifié pour ce numéro.
     */
    public function isVerified(string $telephone, ?string $context = null): bool
    {
        return (bool) Cache::get($this->verifiedKey($telephone, $context));
    }

    /**
     * Supprime l'OTP du cache (après utilisation).
     */
    public function clear(string $telephone, ?string $context = null): void
    {
        Cache::forget($this->key($telephone, $context));
        Cache::forget($this->verifiedKey($telephone, $context));
        Cache::forget($this->attemptsKey($telephone, $context));
    }

    /**
     * Fenêtre glissante (approx. par fenêtre fixe) : incrémente le compteur si la
     * fenêtre en cours est toujours valide, sinon en démarre une nouvelle.
     */
    private function incrementWindow(string $key, int $windowSeconds): void
    {
        $now = now();
        $data = Cache::get($key);

        if (! is_array($data) || $now->timestamp >= $data['expires_at']) {
            $data = ['count' => 0, 'expires_at' => $now->timestamp + $windowSeconds];
        }

        $data['count']++;
        Cache::put($key, $data, $now->addSeconds($windowSeconds));
    }

    private function windowCount(string $key): int
    {
        $data = Cache::get($key);

        if (! is_array($data) || now()->timestamp >= $data['expires_at']) {
            return 0;
        }

        return $data['count'];
    }

    private function windowExpiresAt(string $key): ?int
    {
        $data = Cache::get($key);

        return is_array($data) ? $data['expires_at'] : null;
    }

    /** Masque un numéro de téléphone pour les journaux d'audit (ex: "+2246*****10"). */
    private static function mask(string $telephone): string
    {
        $len = strlen($telephone);

        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($telephone, 0, 4).str_repeat('*', $len - 6).substr($telephone, -2);
    }
}
