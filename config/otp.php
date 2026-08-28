<?php

use App\Enums\OtpPurpose;
use App\Services\Otp\Channels\EmailOtpChannel;

return [
    /*
     * Code OTP fixe utilisé en environnement de test/local pour rendre
     * les tests déterministes. Laisser null en production.
     */
    'fixed_code' => env('OTP_FIXED_CODE'),

    /*
     * Canaux OTP réellement ACTIVABLES, par valeur de App\Enums\OtpChannel —
     * cf. App\Services\Otp\OtpChannelResolver. Seul `email` est câblé
     * aujourd'hui (App\Services\Otp\Channels\EmailOtpChannel).
     *
     * `SmsOtpChannel` (App\Services\Otp\Channels\SmsOtpChannel) existe déjà —
     * c'est le CANAL, jamais un fournisseur. Il délègue à un contrat
     * `App\Contracts\SmsGateway`, qu'aucune classe n'implémente encore
     * (aucun fournisseur SMS configuré). Activer un vrai fournisseur SMS
     * (NimbaSMS, LengoSMS, Twilio...) demande DEUX étapes, jamais une seule
     * ligne ici :
     *   1. Créer ex. NimbaSmsGateway implements App\Contracts\SmsGateway,
     *      et le lier dans un ServiceProvider :
     *      $this->app->bind(SmsGateway::class, NimbaSmsGateway::class);
     *   2. Décommenter la ligne 'sms' ci-dessous — jamais y mettre
     *      directement NimbaSmsGateway::class, toujours SmsOtpChannel::class
     *      (qui résout lui-même son SmsGateway via le conteneur).
     *
     * 'sms' => \App\Services\Otp\Channels\SmsOtpChannel::class,
     * 'whatsapp' => \App\Services\Otp\Channels\WhatsAppOtpChannel::class,
     */
    'channels' => [
        'email' => EmailOtpChannel::class,
    ],

    /*
     * Pour chaque purpose (App\Enums\OtpPurpose), liste ORDONNÉE des canaux
     * souhaités — OtpChannelResolver::firstAvailableFor() retient le premier
     * réellement configuré ci-dessus dans `channels`. Les canaux sms/whatsapp
     * listés ici pour `login`/`phone_verification`/`password_reset` sont
     * aspirationnels : ils deviendront actifs le jour où `channels` les
     * déclarera, sans qu'il faille toucher cette matrice.
     */
    'purpose_channels' => [
        OtpPurpose::LOGIN->value => ['whatsapp', 'sms', 'email'],
        OtpPurpose::PHONE_VERIFICATION->value => ['whatsapp', 'sms', 'email'],
        OtpPurpose::EMAIL_VERIFICATION->value => ['email'],
        OtpPurpose::PASSWORD_RESET->value => ['email', 'whatsapp', 'sms'],
        OtpPurpose::INVITATION->value => ['email'],
    ],
];
