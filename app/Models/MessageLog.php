<?php

namespace App\Models;

use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageLogStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Journal de monitoring des envois SMS/WhatsApp (cf. App\Services\Communications\
 * MessageLogService) — jamais le contenu réel du message ni un code OTP : ce
 * n'est PAS un second système OTP/notifications, seulement l'observation du
 * transport. Alimenté par DEUX origines distinctes, dont la logique métier ne
 * doit jamais se mélanger (cf. rapport notifications de commande, 07/09/2026) :
 *
 * - OTP (`App\Jobs\SendSmsOtpJob`) — `purpose` porte une valeur
 *   `App\Enums\OtpPurpose` (login/phone_verification/...), `recipient_type` et
 *   `messageable` restent toujours `null` (aucune entité métier naturelle à ce
 *   niveau du flux OTP).
 * - Notifications transactionnelles (`App\Services\Communications\
 *   TransactionalCommunicationDispatcher`) — `purpose` porte une valeur
 *   `App\Enums\CommunicationEvent` (commande_confirmee/chargement_valide/
 *   transfert_cree), `recipient_type` (`App\Enums\CommunicationRecipientType`)
 *   et `messageable` (la CommandeVente/TransfertLogistique concernée) sont
 *   renseignés.
 *
 * `purpose` reste volontairement une simple chaîne (pas de cast enum) : les
 * deux origines ci-dessus utilisent chacune leur propre vocabulaire, jamais un
 * enum unique qui forcerait à les fusionner.
 *
 * Voir App\Enums\MessageLogStatus pour les limites volontaires du P1 (pas de
 * statut `delivered` tant que le webhook Nimba n'est pas vérifié).
 */
class MessageLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'channel',
        'direction',
        'purpose',
        'recipient_type',
        'provider',
        'provider_message_id',
        'masked_recipient',
        'status',
        'provider_status',
        'error_code',
        'error_message',
        'messageable_type',
        'messageable_id',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'direction' => MessageDirection::class,
            'recipient_type' => CommunicationRecipientType::class,
            'status' => MessageLogStatus::class,
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function messageable(): MorphTo
    {
        return $this->morphTo();
    }
}
