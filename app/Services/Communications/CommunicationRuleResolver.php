<?php

namespace App\Services\Communications;

use App\Contracts\WhatsAppGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Models\CommunicationRule;

/**
 * Unique source de vérité pour "cette règle est-elle active ?" (cf. rapport
 * notifications de commande, 07/09/2026) — jamais interrogé directement par
 * `App\Models\CommunicationRule::query()` ailleurs dans l'application.
 *
 * Vérifie TOUJOURS `WhatsAppGateway::isConfigured()` en plus du flag `enabled`
 * en base, même si `App\Http\Controllers\Settings\CommunicationRuleController::
 * update()` refuse déjà d'enregistrer une règle WhatsApp active sans
 * fournisseur configuré : défense en profondeur si la configuration change
 * après coup (fournisseur retiré) sans que les règles déjà enregistrées ne
 * soient retouchées. Il ne doit jamais exister un état où l'administrateur
 * voit une règle WhatsApp "active" alors qu'aucun envoi ne part réellement.
 */
class CommunicationRuleResolver
{
    public function __construct(private readonly WhatsAppGateway $whatsapp) {}

    public function isEnabled(
        string $organizationId,
        CommunicationModule $module,
        CommunicationEvent $event,
        CommunicationRecipientType $recipientType,
        MessageChannel $channel,
        ?ClientType $clientType = null,
    ): bool {
        if ($channel === MessageChannel::WHATSAPP && ! $this->whatsapp->isConfigured()) {
            return false;
        }

        return CommunicationRule::query()
            ->where('organization_id', $organizationId)
            ->where('module', $module->value)
            ->where('event', $event->value)
            ->where('recipient_type', $recipientType->value)
            ->where('client_type', $clientType?->value)
            ->where('channel', $channel->value)
            ->where('enabled', true)
            ->exists();
    }
}
