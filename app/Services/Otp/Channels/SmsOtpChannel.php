<?php

namespace App\Services\Otp\Channels;

use App\Contracts\OtpDeliveryChannel;
use App\Contracts\SmsGateway;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Jobs\SendSmsOtpJob;
use App\Services\Otp\OtpFallbackTarget;
use App\Services\OtpService;

/**
 * Canal OTP métier "SMS" — délègue l'envoi réel à un `SmsGateway` injecté
 * (aujourd'hui `App\Services\Sms\NimbaSmsGateway`, cf. binding dans
 * `AppServiceProvider`), jamais implémenté directement par un fournisseur
 * (cf. rapport du 27/08/2026, docblock de `SmsGateway`). Cette classe ne
 * contient aucun appel HTTP — c'est de la pure plomberie, correcte dès qu'un
 * vrai `SmsGateway` est lié dans le conteneur.
 *
 * Enregistré dans `config('otp.channels')` depuis l'intégration Nimba SMS
 * (audit du 31/08/2026). `$gateway` reste injecté ici même si `send()` ne
 * l'appelle plus directement (voir plus bas) : ça garantit qu'un `SmsGateway`
 * est bien lié dans le conteneur dès la RÉSOLUTION de ce canal
 * (`OtpChannelResolver::resolve()`) — sans lui, la résolution échouerait
 * immédiatement avec une erreur claire, plutôt que de découvrir l'absence de
 * fournisseur plus tard au moment d'un envoi. Ne jamais retirer cette
 * dépendance en la jugeant "inutilisée".
 */
final class SmsOtpChannel implements OtpDeliveryChannel
{
    public function __construct(
        private readonly SmsGateway $gateway,
        private readonly OtpService $otp,
    ) {}

    public function channel(): OtpChannel
    {
        return OtpChannel::SMS;
    }

    public function isAvailable(): bool
    {
        // Un SmsGateway EST lié (sinon la résolution de ce canal aurait déjà
        // échoué), mais ses identifiants peuvent être absents (ex: variables
        // NIMBA_SMS_* vides) — dans ce cas le canal n'est pas réellement
        // disponible, cf. OtpChannelResolver::firstAvailableFor().
        return $this->gateway->isConfigured();
    }

    /**
     * Ne bloque jamais la requête HTTP sur l'appel réseau fournisseur (cf.
     * docblock `OtpDeliveryChannel::send()`) : l'envoi réel est délégué à
     * `SendSmsOtpJob`, qui résout lui-même son `SmsGateway` à l'exécution.
     *
     * `$fallback` (cf. `OtpChannelResolver::fallbackFor()`) est transmis tel
     * quel au job : si l'appel Nimba échoue réellement (panne, solde
     * insuffisant, sender name refusé, timeout...), le job retransporte le
     * MÊME `$code` par ce canal de repli — jamais recalculé/deviné ici.
     */
    public function send(string $destination, string $code, OtpPurpose $purpose, ?OtpFallbackTarget $fallback = null): void
    {
        $message = "Votre code Eau La Maman est : {$code}. Il expire dans {$this->otp->ttlMinutes()} minutes.";

        SendSmsOtpJob::dispatch($destination, $message, $code, $purpose, $fallback);
    }
}
