<?php

namespace App\Contracts;

/**
 * Contrat FOURNISSEUR SMS — distinct de `OtpDeliveryChannel` (le canal OTP
 * métier). Cf. rapport du 27/08/2026 : mélanger les deux niveaux (un
 * fournisseur comme NimbaSMS/LengoSMS/Twilio implémentant directement
 * `OtpDeliveryChannel`) recommence à confondre "canal" et "prestataire" dès
 * le second fournisseur SMS ajouté.
 *
 * Architecture cible :
 * `App\Services\Otp\Channels\SmsOtpChannel` (implémente `OtpDeliveryChannel`,
 * c'est LE canal SMS métier) délègue l'envoi réel à un `SmsGateway` injecté —
 * `NimbaSmsGateway`, `LengoSmsGateway`, `TwilioSmsGateway` implémentent CE
 * contrat-ci, jamais `OtpDeliveryChannel` directement. Changer de fournisseur
 * = changer quelle implémentation de `SmsGateway` est liée dans le
 * conteneur, sans jamais toucher `SmsOtpChannel`, `OtpService`, ou la
 * logique d'authentification.
 *
 * `App\Services\Sms\NimbaSmsGateway` (Nimba SMS, cf. audit du 31/08/2026)
 * implémente ce contrat — premier fournisseur SMS réellement câblé.
 */
interface SmsGateway
{
    /**
     * Le fournisseur a-t-il ses identifiants réellement renseignés ? Permet à
     * `SmsOtpChannel::isAvailable()` — donc à
     * `OtpChannelResolver::firstAvailableFor()` — de sauter le canal SMS
     * plutôt que de le choisir puis échouer silencieusement à l'envoi (ex:
     * canal `sms` déclaré dans `config('otp.channels')` mais variables
     * d'environnement du fournisseur non renseignées).
     */
    public function isConfigured(): bool;

    /**
     * @throws \RuntimeException Fournisseur non configuré, réponse en échec,
     *                           ou erreur réseau/timeout.
     */
    public function send(string $phoneNumber, string $message): void;
}
