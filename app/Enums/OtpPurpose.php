<?php

namespace App\Enums;

/**
 * Pourquoi un code OTP existe — jamais un canal de transport (cf. OtpChannel) ni
 * une preuve de vérification d'identité (cf. UserAuthIdentity::verified_at). Ces
 * trois notions restent strictement séparées (cf. rapport du 27/08/2026,
 * chantier OTP agnostique du canal) : un même purpose peut être livré par
 * différents canaux selon ce qui est disponible, sans jamais changer ce que la
 * vérification réussie du code autorise ensuite.
 *
 * Chaque purpose scope un challenge OTP indépendant dans le cache
 * (OtpService::generate()/verify()) — un code généré pour `login` n'est jamais
 * valide pour `phone_verification` sur le même numéro, même généré au même
 * instant.
 */
enum OtpPurpose: string
{
    case LOGIN = 'login';
    case PHONE_VERIFICATION = 'phone_verification';
    case EMAIL_VERIFICATION = 'email_verification';
    case PASSWORD_RESET = 'password_reset';
    case INVITATION = 'invitation';
}
