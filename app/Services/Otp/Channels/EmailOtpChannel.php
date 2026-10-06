<?php

namespace App\Services\Otp\Channels;

use App\Contracts\OtpDeliveryChannel;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Mail\OtpCodeMail;
use App\Services\Otp\OtpFallbackTarget;
use Illuminate\Support\Facades\Mail;

/**
 * Seul canal OTP réellement opérationnel aujourd'hui (cf. rapport du
 * 27/08/2026) — utilisé pour les NOUVEAUX usages agnostiques du canal
 * (login par OTP, vérification téléphone en secours) via
 * `OtpService::generateAndSend()`. Les envois OTP déjà en place avant ce
 * chantier (mot de passe oublié, invitation, installation) gardent leurs
 * Mailables dédiés (`OtpPasswordResetMail`, `OtpInvitationMail`,
 * `InstallEmailVerificationMail`) — non modifiés, aucune raison de les faire
 * transiter par ce canal générique.
 *
 * Reste synchrone (pas de Job/queue) : un code OTP à durée de vie courte doit
 * arriver au plus vite, exactement comme les envois email OTP déjà en place
 * dans ce projet.
 */
final class EmailOtpChannel implements OtpDeliveryChannel
{
    public function channel(): OtpChannel
    {
        return OtpChannel::EMAIL;
    }

    public function isAvailable(): bool
    {
        // Toujours vrai : l'email n'a pas de fournisseur/identifiants
        // séparés à vérifier ici (cf. docblock App\Contracts\OtpDeliveryChannel).
        return true;
    }

    public function send(string $destination, string $code, OtpPurpose $purpose, ?OtpFallbackTarget $fallback = null): void
    {
        // $fallback ignoré : envoi synchrone, un échec remonte déjà en
        // exception à l'appelant (cf. docblock App\Contracts\OtpDeliveryChannel::send()).
        Mail::to($destination)->send(new OtpCodeMail($code, $purpose));
    }
}
