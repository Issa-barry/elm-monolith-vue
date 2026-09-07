<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageLogStatus;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Journal de monitoring des envois SMS/WhatsApp (cf. App\Services\Communications\
 * MessageLogService) — jamais le contenu réel du message ni un code OTP : ce
 * n'est PAS un second système OTP, seulement l'observation du transport. Voir
 * App\Enums\MessageLogStatus pour les limites volontaires du P1 (pas de statut
 * `delivered` tant que le webhook Nimba n'est pas vérifié).
 */
class MessageLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'channel',
        'direction',
        'purpose',
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
            'channel' => OtpChannel::class,
            'direction' => MessageDirection::class,
            'purpose' => OtpPurpose::class,
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
