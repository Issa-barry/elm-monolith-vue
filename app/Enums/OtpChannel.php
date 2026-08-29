<?php

namespace App\Enums;

use App\Models\UserAuthIdentity;

/**
 * Par quel canal un code OTP a été transporté — jamais confondu avec le
 * `purpose` (pourquoi le code existe) ni avec `UserAuthIdentity::verified_at`
 * (ce qui a réellement été prouvé). Un même purpose (ex: `login`) peut être
 * livré aujourd'hui par email, demain par WhatsApp/SMS, sans que la logique
 * d'authentification n'ait à changer (cf. OtpDeliveryChannel).
 */
enum OtpChannel: string
{
    case EMAIL = 'email';
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';

    /**
     * Un canal ne prouve la possession que du type d'identité qu'il atteint
     * RÉELLEMENT — un email ne prouve jamais un téléphone, ni l'inverse. Règle
     * centrale exploitée par UserAuthIdentity::markVerifiedVia(), jamais
     * dupliquée ailleurs sous forme de in_array()/if ad hoc : c'est ce qui
     * empêche structurellement qu'un OTP envoyé par email marque un téléphone
     * comme vérifié.
     */
    public function provesPossessionOf(string $identityType): bool
    {
        return match ($this) {
            self::EMAIL => $identityType === UserAuthIdentity::TYPE_EMAIL,
            self::SMS, self::WHATSAPP => $identityType === UserAuthIdentity::TYPE_TELEPHONE,
        };
    }
}
