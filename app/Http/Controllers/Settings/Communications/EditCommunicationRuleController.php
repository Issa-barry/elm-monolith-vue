<?php

namespace App\Http\Controllers\Settings\Communications;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Models\CommunicationRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran Paramètres → Communications (cf. rapport notifications de commande, 07/09/2026) —
 * configure les 5 règles réellement câblées (cf. App\Enums\CommunicationEvent) : jamais un
 * formulaire libre, `UpdateCommunicationRuleController::VALID_RULE_KEYS` est la liste fermée des
 * combinaisons (module, event, recipient_type) que le moteur sait déclencher. Permission dédiée
 * `communications.manage` (distincte de `communications.read`, réservée au monitoring) — cf.
 * App\Support\Permissions\PermissionCatalog.
 */
class EditCommunicationRuleController extends Controller
{
    public function __invoke(SmsGateway $sms, WhatsAppGateway $whatsapp): Response
    {
        abort_unless(auth()->user()->can('communications.manage'), 403);

        $orgId = auth()->user()->organization_id;
        abort_unless($orgId !== null, 403);

        $rules = CommunicationRule::where('organization_id', $orgId)->get();

        $enabled = fn (CommunicationModule $module, CommunicationEvent $event, CommunicationRecipientType $recipient, MessageChannel $channel, ?ClientType $clientType = null): bool => $rules
            ->contains(fn (CommunicationRule $r) => $r->module === $module
                && $r->event === $event
                && $r->recipient_type === $recipient
                && $r->client_type === $clientType
                && $r->channel === $channel
                && $r->enabled);

        $livreurRow = fn (CommunicationModule $module, CommunicationEvent $event): array => [
            'sms' => $enabled($module, $event, CommunicationRecipientType::LIVREUR, MessageChannel::SMS),
            'whatsapp' => $enabled($module, $event, CommunicationRecipientType::LIVREUR, MessageChannel::WHATSAPP),
        ];

        $clientRows = collect(ClientType::cases())->mapWithKeys(fn (ClientType $type) => [
            $type->value => [
                'sms' => $enabled(CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, MessageChannel::SMS, $type),
                'whatsapp' => $enabled(CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, MessageChannel::WHATSAPP, $type),
            ],
        ]);

        return Inertia::render('settings/Communications', [
            'channel_availability' => [
                'sms' => $sms->isConfigured(),
                'whatsapp' => $whatsapp->isConfigured(),
            ],
            'client_types' => ClientType::options(),
            'ventes' => [
                'commande_confirmee' => [
                    'livreur' => $livreurRow(CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE),
                ],
                'chargement_valide' => [
                    'livreur' => $livreurRow(CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE),
                    'client' => $clientRows,
                ],
            ],
            'logistique' => [
                'transfert_cree' => [
                    'livreur' => $livreurRow(CommunicationModule::LOGISTIQUE, CommunicationEvent::TRANSFERT_CREE),
                ],
                'chargement_valide' => [
                    'livreur' => $livreurRow(CommunicationModule::LOGISTIQUE, CommunicationEvent::CHARGEMENT_VALIDE),
                ],
            ],
        ]);
    }
}
