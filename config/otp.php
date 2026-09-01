<?php

use App\Enums\OtpPurpose;
use App\Services\Otp\Channels\EmailOtpChannel;
use App\Services\Otp\Channels\SmsOtpChannel;

return [
    /*
     * Code OTP fixe utilisé en environnement de test/local pour rendre
     * les tests déterministes. Laisser null en production.
     */
    'fixed_code' => env('OTP_FIXED_CODE'),

    /*
     * Canaux OTP réellement ACTIVABLES, par valeur de App\Enums\OtpChannel —
     * cf. App\Services\Otp\OtpChannelResolver.
     *
     * `email` (App\Services\Otp\Channels\EmailOtpChannel) et `sms`
     * (App\Services\Otp\Channels\SmsOtpChannel, fournisseur Nimba SMS —
     * App\Services\Sms\NimbaSmsGateway lié dans AppServiceProvider, cf. audit
     * du 31/08/2026) sont câblés. Ces classes sont des CANAUX, jamais un
     * fournisseur — SmsOtpChannel délègue à `App\Contracts\SmsGateway`,
     * résolu via le conteneur. `NIMBA_SMS_SERVICE_ID`/`NIMBA_SMS_SECRET_TOKEN`/
     * `NIMBA_SMS_SENDER_NAME` absents ou vides = canal SMS silencieusement
     * indisponible (SmsOtpChannel::isAvailable()), la résolution retombe sur
     * le canal suivant de `purpose_channels` plutôt que d'échouer.
     *
     * Changer de fournisseur SMS (Nimba -> LengoSMS, Twilio...) = changer
     * uniquement la liaison `SmsGateway` dans AppServiceProvider, jamais
     * cette ligne ni SmsOtpChannel/OtpService/les contrôleurs.
     *
     * 'whatsapp' => \App\Services\Otp\Channels\WhatsAppOtpChannel::class,
     */
    'channels' => [
        'email' => EmailOtpChannel::class,
        'sms' => SmsOtpChannel::class,
    ],

    /*
     * Pour chaque purpose (App\Enums\OtpPurpose), liste ORDONNÉE des canaux
     * souhaités — OtpChannelResolver::firstAvailableFor() retient le premier
     * réellement DISPONIBLE ci-dessous (déclaré dans `channels` + fournisseur
     * configuré, cf. isAvailable()). `sms` est câblé depuis le 31/08/2026
     * (Nimba) : pour `login`/`phone_verification`, il devient le canal
     * réellement utilisé dès que Nimba est configuré (whatsapp ne l'est
     * jamais encore) — le téléphone étant toujours connu (c'est l'identifiant
     * de connexion), sans qu'il faille toucher cette matrice. `whatsapp`
     * reste aspirationnel : il deviendra actif le jour où `channels` le
     * déclarera.
     */
    'purpose_channels' => [
        OtpPurpose::LOGIN->value => ['whatsapp', 'sms', 'email'],
        OtpPurpose::PHONE_VERIFICATION->value => ['whatsapp', 'sms', 'email'],
        OtpPurpose::EMAIL_VERIFICATION->value => ['email'],
        OtpPurpose::PASSWORD_RESET->value => ['email', 'whatsapp', 'sms'],
        OtpPurpose::INVITATION->value => ['email'],
    ],
];
