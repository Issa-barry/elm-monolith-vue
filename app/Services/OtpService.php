<?php

namespace App\Services;

use App\Contracts\OtpDeliveryChannel;
use App\Enums\OtpPurpose;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Moteur OTP agnostique du canal et du type d'identifiant (téléphone ou email
 * — cf. rapport du 27/08/2026, chantier OTP agnostique du canal). `$identifier`
 * remplace l'ancien `$telephone` : ce service sert déjà à vérifier des emails
 * (InstallationService, pendant l'installation) exactement comme des
 * téléphones — seul un nom de paramètre correct change ici, aucun
 * comportement.
 *
 * `purpose` (pourquoi ce code existe — cf. OtpPurpose) scope chaque challenge
 * indépendamment : un code généré pour `login` n'est jamais valide pour
 * `phone_verification` sur le même identifiant, même généré au même instant.
 * En revanche les compteurs anti-abus (cooldown, plafonds horaire/journalier)
 * restent scopés par IDENTIFIANT SEUL, sans purpose — sinon un même
 * téléphone pourrait multiplier son quota d'envois en demandant tour à tour
 * un code par purpose différent.
 *
 * Ce service ne décide JAMAIS si une identité doit être considérée vérifiée
 * (cf. `UserAuthIdentity::markVerifiedVia()`, seul point qui applique cette
 * règle) — il ne fait que générer/transporter/valider un code.
 */
class OtpService
{
    private const TTL_MINUTES = 10;

    /** Au-delà de ce nombre d'essais infructueux, le code est verrouillé (et supprimé) : il faut en redemander un nouveau. */
    private const MAX_ATTEMPTS = 5;

    /** Délai minimal entre deux envois de code pour un même identifiant (anti-spam). */
    private const RESEND_COOLDOWN_SECONDS = 30;

    private const MAX_SENDS_PER_HOUR = 5;

    private const MAX_SENDS_PER_DAY = 10;

    /**
     * Construit une clé de cache pour ce challenge OTP précis (identifiant +
     * purpose), optionnellement liée à un contexte (ex: l'identifiant d'une
     * invitation) pour qu'un même identifiant ne partage pas le même code
     * d'un contexte à l'autre.
     */
    private function challengeCacheKey(string $prefix, string $identifier, OtpPurpose $purpose, ?string $context): string
    {
        $identity = $identifier.'|'.$purpose->value.($context !== null ? '|'.$context : '');

        return $prefix.':'.md5($identity);
    }

    /**
     * Clé de cache pour les compteurs anti-abus — scopée par IDENTIFIANT SEUL
     * (sans purpose) : un même numéro/email ne doit pas pouvoir contourner le
     * plafond d'envois en alternant les purposes.
     */
    private function rateLimitCacheKey(string $prefix, string $identifier, ?string $context): string
    {
        $identity = $context !== null ? $identifier.'|'.$context : $identifier;

        return $prefix.':'.md5($identity);
    }

    private function key(string $identifier, OtpPurpose $purpose, ?string $context): string
    {
        return $this->challengeCacheKey('otp', $identifier, $purpose, $context);
    }

    private function verifiedKey(string $identifier, OtpPurpose $purpose, ?string $context): string
    {
        return $this->challengeCacheKey('otp:verified', $identifier, $purpose, $context);
    }

    private function attemptsKey(string $identifier, OtpPurpose $purpose, ?string $context): string
    {
        return $this->challengeCacheKey('otp:attempts', $identifier, $purpose, $context);
    }

    private function cooldownKey(string $identifier, ?string $context): string
    {
        return $this->rateLimitCacheKey('otp:cooldown', $identifier, $context);
    }

    private function hourlyKey(string $identifier, ?string $context): string
    {
        return $this->rateLimitCacheKey('otp:hourly', $identifier, $context);
    }

    private function dailyKey(string $identifier, ?string $context): string
    {
        return $this->rateLimitCacheKey('otp:daily', $identifier, $context);
    }

    /**
     * Génère un OTP à 6 chiffres pour cet identifiant/purpose et le stocke en
     * cache. Réinitialise le compteur de tentatives, démarre le délai anti-spam de
     * renvoi et incrémente les compteurs horaire/journalier (partagés entre purposes).
     *
     * @param  string|null  $context  Lie le code à un contexte précis (ex: id d'invitation) pour éviter qu'un code généré pour un contexte soit valide dans un autre.
     */
    public function generate(string $identifier, OtpPurpose $purpose, ?string $context = null): string
    {
        $code = config('otp.fixed_code')
            ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($identifier, $purpose, $context), $code, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget($this->verifiedKey($identifier, $purpose, $context));
        Cache::forget($this->attemptsKey($identifier, $purpose, $context));

        $cooldownUntil = now()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
        Cache::put($this->cooldownKey($identifier, $context), $cooldownUntil->timestamp, $cooldownUntil);

        $this->incrementWindow($this->hourlyKey($identifier, $context), 3600);
        $this->incrementWindow($this->dailyKey($identifier, $context), 86400);

        Log::info('otp.sent', ['identifier' => self::mask($identifier), 'purpose' => $purpose->value, 'context' => $context]);

        return $code;
    }

    /**
     * Génère un code puis le transporte immédiatement via le canal donné —
     * combine `generate()` et `OtpDeliveryChannel::send()` pour les nouveaux
     * appelants. Les appelants historiques (Mailables dédiés déjà en place :
     * OtpPasswordResetMail, OtpInvitationMail...) peuvent continuer à utiliser
     * `generate()` seul et envoyer eux-mêmes, sans passer par ce raccourci —
     * aucune obligation de migrer un envoi déjà correct.
     *
     * `$identifier` (clé du challenge, ex: le téléphone qu'on cherche à
     * vérifier/utiliser pour se connecter) et `$destination` (où envoyer,
     * ex: un email de secours) peuvent différer — c'est exactement le cas
     * "canal temporaire" (purpose=phone_verification ou login, transporté par
     * email en attendant un fournisseur SMS/WhatsApp).
     */
    public function generateAndSend(
        string $identifier,
        OtpPurpose $purpose,
        OtpDeliveryChannel $channel,
        string $destination,
        ?string $context = null
    ): string {
        $code = $this->generate($identifier, $purpose, $context);

        $channel->send($destination, $code, $purpose);

        return $code;
    }

    /** Nombre de secondes d'attente avant de pouvoir redemander un code (0 = autorisé). */
    public function resendCooldownSeconds(): int
    {
        return self::RESEND_COOLDOWN_SECONDS;
    }

    /**
     * Durée de vie (en minutes) d'un code généré — exposé pour que les canaux
     * de transport (ex: SmsOtpChannel) puissent l'annoncer dans le message
     * envoyé sans dupliquer cette valeur (single source of truth).
     */
    public function ttlMinutes(): int
    {
        return self::TTL_MINUTES;
    }

    /**
     * Indique si un nouveau code peut être demandé pour cet identifiant : ni avant le
     * délai anti-spam (30s), ni au-delà des plafonds horaire/journalier — tous
     * purposes confondus (cf. docblock de classe).
     */
    public function canSend(string $identifier, ?string $context = null): bool
    {
        return $this->resendWaitSeconds($identifier, $context) === 0;
    }

    /**
     * Secondes à attendre avant qu'un nouvel envoi soit autorisé (0 si immédiat).
     * Retient le motif de blocage dont l'échéance est la plus lointaine parmi le
     * délai anti-spam et les plafonds horaire/journalier : peu importe la cause
     * exacte, on ne révèle que le temps d'attente au client (aucune fuite d'info).
     */
    public function resendWaitSeconds(string $identifier, ?string $context = null): int
    {
        $now = now()->timestamp;
        $waits = [];

        $cooldownUntil = Cache::get($this->cooldownKey($identifier, $context));
        if (is_int($cooldownUntil)) {
            $waits[] = $cooldownUntil - $now;
        }

        if ($this->windowCount($this->hourlyKey($identifier, $context)) >= self::MAX_SENDS_PER_HOUR) {
            $waits[] = ($this->windowExpiresAt($this->hourlyKey($identifier, $context)) ?? $now) - $now;
        }

        if ($this->windowCount($this->dailyKey($identifier, $context)) >= self::MAX_SENDS_PER_DAY) {
            $waits[] = ($this->windowExpiresAt($this->dailyKey($identifier, $context)) ?? $now) - $now;
        }

        return $waits === [] ? 0 : max(0, max($waits));
    }

    /**
     * Vérifie si le code fourni correspond à celui stocké en cache pour cet
     * identifiant/purpose. Comparaison à temps constant (hash_equals) pour éviter
     * les attaques par timing. Le code est verrouillé et supprimé après
     * MAX_ATTEMPTS essais infructueux, et supprimé également après un succès
     * (usage unique).
     */
    public function verify(string $identifier, string $code, OtpPurpose $purpose, ?string $context = null): bool
    {
        if ($this->tooManyAttempts($identifier, $purpose, $context)) {
            return false;
        }

        $stored = Cache::get($this->key($identifier, $purpose, $context));
        $matches = is_string($stored) && hash_equals($stored, $code);

        if ($matches) {
            Cache::forget($this->key($identifier, $purpose, $context));
            Cache::forget($this->attemptsKey($identifier, $purpose, $context));
            Log::info('otp.validated', ['identifier' => self::mask($identifier), 'purpose' => $purpose->value, 'context' => $context]);

            return true;
        }

        $this->recordFailedAttempt($identifier, $purpose, $context);

        return false;
    }

    /**
     * Indique si le nombre maximal de tentatives infructueuses est atteint pour ce
     * challenge : le code en cours est verrouillé, il faut en redemander un nouveau.
     */
    public function tooManyAttempts(string $identifier, OtpPurpose $purpose, ?string $context = null): bool
    {
        return (int) Cache::get($this->attemptsKey($identifier, $purpose, $context), 0) >= self::MAX_ATTEMPTS;
    }

    /**
     * Indique si un code est encore actif en cache pour ce challenge (ni expiré par
     * TTL naturel, ni jamais généré). Utilisé pour distinguer "code expiré" de
     * "code incorrect" côté contrôleur.
     */
    public function hasActiveCode(string $identifier, OtpPurpose $purpose, ?string $context = null): bool
    {
        return Cache::has($this->key($identifier, $purpose, $context));
    }

    private function recordFailedAttempt(string $identifier, OtpPurpose $purpose, ?string $context): void
    {
        $key = $this->attemptsKey($identifier, $purpose, $context);
        $attempts = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes(self::TTL_MINUTES));

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Verrouillage : le code est supprimé, un nouveau devra être redemandé.
            Cache::forget($this->key($identifier, $purpose, $context));
            Log::warning('otp.blocked', ['identifier' => self::mask($identifier), 'purpose' => $purpose->value, 'context' => $context]);
        } else {
            Log::info('otp.incorrect', ['identifier' => self::mask($identifier), 'purpose' => $purpose->value, 'context' => $context, 'attempts' => $attempts]);
        }
    }

    /**
     * Marque l'OTP comme vérifié pour ce challenge (TTL identique). Purement
     * interne au service (état "ce code a été validé" en cache) — n'implique
     * JAMAIS `UserAuthIdentity::verified_at`, décidé séparément au bon point
     * métier (cf. `UserAuthIdentity::markVerifiedVia()`).
     */
    public function markVerified(string $identifier, OtpPurpose $purpose, ?string $context = null): void
    {
        Cache::put($this->verifiedKey($identifier, $purpose, $context), true, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Indique si l'OTP a été vérifié pour ce challenge.
     */
    public function isVerified(string $identifier, OtpPurpose $purpose, ?string $context = null): bool
    {
        return (bool) Cache::get($this->verifiedKey($identifier, $purpose, $context));
    }

    /**
     * Supprime l'OTP du cache (après utilisation) pour ce challenge.
     */
    public function clear(string $identifier, OtpPurpose $purpose, ?string $context = null): void
    {
        Cache::forget($this->key($identifier, $purpose, $context));
        Cache::forget($this->verifiedKey($identifier, $purpose, $context));
        Cache::forget($this->attemptsKey($identifier, $purpose, $context));
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

    /** Masque un identifiant (téléphone ou email) pour les journaux d'audit. */
    private static function mask(string $identifier): string
    {
        $len = strlen($identifier);

        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($identifier, 0, 4).str_repeat('*', $len - 6).substr($identifier, -2);
    }
}
