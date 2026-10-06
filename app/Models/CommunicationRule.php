<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une case à cocher de l'écran Paramètres → Communications — active/désactive
 * l'envoi SMS/WhatsApp pour UN (événement, destinataire[, type de client],
 * canal) précis, par organisation. Jamais lue directement par les
 * contrôleurs métier : cf. App\Services\Communications\CommunicationRuleResolver.
 */
class CommunicationRule extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'module',
        'event',
        'recipient_type',
        'client_type',
        'channel',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'module' => CommunicationModule::class,
            'event' => CommunicationEvent::class,
            'recipient_type' => CommunicationRecipientType::class,
            'client_type' => ClientType::class,
            'channel' => MessageChannel::class,
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
