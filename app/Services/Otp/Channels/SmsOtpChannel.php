<?php

namespace App\Services\Otp\Channels;

use App\Contracts\OtpDeliveryChannel;
use App\Contracts\SmsGateway;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;

/**
 * Canal OTP métier "SMS" — délègue l'envoi réel à un `SmsGateway` injecté
 * (NimbaSMS, LengoSMS, Twilio...), jamais implémenté directement par un
 * fournisseur (cf. rapport du 27/08/2026, docblock de `SmsGateway`). Cette
 * classe ne contient aucun appel HTTP/fournisseur simulé — c'est de la
 * pure plomberie, correcte dès qu'un vrai `SmsGateway` est lié dans le
 * conteneur.
 *
 * PAS ENCORE enregistré dans `config('otp.channels')` : aucun `SmsGateway`
 * n'est lié aujourd'hui (aucun fournisseur SMS configuré), donc résoudre
 * cette classe échouerait avec une erreur claire ("aucune liaison pour
 * SmsGateway") plutôt que d'envoyer silencieusement nulle part. L'activer
 * demain = lier un `SmsGateway` concret dans un ServiceProvider PUIS
 * décommenter la ligne correspondante dans `config('otp.channels')` —
 * jamais modifier cette classe, `OtpService`, ni la logique d'authentification.
 */
final class SmsOtpChannel implements OtpDeliveryChannel
{
    public function __construct(private readonly SmsGateway $gateway) {}

    public function channel(): OtpChannel
    {
        return OtpChannel::SMS;
    }

    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        $this->gateway->send($destination, "Votre code : {$code}");
    }
}
