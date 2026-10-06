<?php

namespace App\Enums;

/**
 * Canal de transport d'un `App\Models\MessageLog` — générique, réutilisé aussi
 * bien par le transport OTP (cf. App\Services\Communications\MessageLogService::
 * logSmsOtpAttempt()) que par les notifications métier transactionnelles (cf.
 * TransactionalCommunicationDispatcher). Distinct de `App\Enums\OtpChannel`
 * (qui reste réservé à la résolution de canal OTP — LOGIN/PHONE_VERIFICATION/
 * etc. via `App\Services\Otp\OtpChannelResolver`, avec EMAIL en plus) : ce
 * découplage date du chantier notifications de commande (07/09/2026) — en P1
 * `message_logs` ne concernait que l'OTP, réutiliser `OtpChannel` était encore
 * acceptable, ce n'est plus le cas dès qu'une notification non-OTP écrit dans
 * la même table.
 *
 * Volontairement SANS `EMAIL` : les notifications transactionnelles (commande,
 * chargement, transfert) ne ciblent que SMS/WhatsApp — jamais fusionné avec le
 * canal email OTP.
 */
enum MessageChannel: string
{
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::SMS => 'SMS',
            self::WHATSAPP => 'WhatsApp',
        };
    }
}
