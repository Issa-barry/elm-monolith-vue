<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

class OtpService
{
    // TODO: Remplacer par un vrai code généré aléatoirement et envoyé par SMS.
    // Intégrations possibles : Africa's Talking, Twilio, Orange SMS API, etc.
    // La méthode generate() devra appeler le provider configuré en injection.
    private const FIXED_CODE = '12345';

    private const SESSION_KEY = 'register_otp';

    /**
     * Génère un OTP pour le numéro de téléphone donné et le stocke en session.
     *
     * TODO: Envoyer le code par SMS via le provider configuré.
     */
    public function generate(string $telephone): string
    {
        // TODO: $code = (string) random_int(10000, 99999);
        $code = self::FIXED_CODE;

        Session::put(self::SESSION_KEY.'.'.$telephone, [
            'code' => $code,
            'verified' => false,
            'generated_at' => now()->timestamp,
        ]);

        return $code;
    }

    /**
     * Vérifie si le code fourni correspond à celui stocké en session pour ce téléphone.
     */
    public function verify(string $telephone, string $code): bool
    {
        $data = Session::get(self::SESSION_KEY.'.'.$telephone);

        if (! $data) {
            return false;
        }

        return $data['code'] === $code;
    }

    /**
     * Marque l'OTP comme vérifié pour ce numéro.
     */
    public function markVerified(string $telephone): void
    {
        $data = Session::get(self::SESSION_KEY.'.'.$telephone);

        if ($data) {
            $data['verified'] = true;
            Session::put(self::SESSION_KEY.'.'.$telephone, $data);
        }
    }

    /**
     * Indique si l'OTP a été vérifié pour ce numéro.
     */
    public function isVerified(string $telephone): bool
    {
        $data = Session::get(self::SESSION_KEY.'.'.$telephone);

        return (bool) ($data['verified'] ?? false);
    }

    /**
     * Supprime l'OTP de la session (après utilisation).
     */
    public function clear(string $telephone): void
    {
        Session::forget(self::SESSION_KEY.'.'.$telephone);
    }
}
