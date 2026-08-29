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
 * Aucune implémentation n'existe encore (aucun fournisseur SMS n'est
 * configuré aujourd'hui) — ce contrat est volontairement vide de toute
 * implémentation réelle, pour ne pas simuler une intégration qui n'existe
 * pas.
 */
interface SmsGateway
{
    public function send(string $phoneNumber, string $message): void;
}
