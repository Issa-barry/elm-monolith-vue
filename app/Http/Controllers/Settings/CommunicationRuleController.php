<?php

namespace App\Http\Controllers\Settings;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Models\CommunicationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran Paramètres → Communications (cf. rapport notifications de commande,
 * 07/09/2026) — configure les 5 règles réellement câblées (cf.
 * App\Enums\CommunicationEvent) : jamais un formulaire libre, `VALID_RULE_KEYS`
 * ci-dessous est la liste fermée des combinaisons (module, event,
 * recipient_type) que le moteur sait déclencher. Permission dédiée
 * `communications.manage` (distincte de `communications.read`, réservée au
 * monitoring) — cf. App\Support\Permissions\PermissionCatalog.
 */
class CommunicationRuleController extends Controller
{
    /**
     * @var list<array{module: string, event: string, recipient_type: string}>
     */
    private const VALID_RULE_KEYS = [
        ['module' => 'ventes', 'event' => 'commande_confirmee', 'recipient_type' => 'livreur'],
        ['module' => 'ventes', 'event' => 'chargement_valide', 'recipient_type' => 'livreur'],
        ['module' => 'ventes', 'event' => 'chargement_valide', 'recipient_type' => 'client'],
        ['module' => 'logistique', 'event' => 'transfert_cree', 'recipient_type' => 'livreur'],
        ['module' => 'logistique', 'event' => 'chargement_valide', 'recipient_type' => 'livreur'],
    ];

    public function edit(SmsGateway $sms, WhatsAppGateway $whatsapp): Response
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

    public function update(Request $request, SmsGateway $sms, WhatsAppGateway $whatsapp): RedirectResponse
    {
        abort_unless(auth()->user()->can('communications.manage'), 403);

        $orgId = auth()->user()->organization_id;
        abort_unless($orgId !== null, 403);

        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.module' => ['required', 'string', 'in:ventes,logistique'],
            'rules.*.event' => ['required', 'string', 'in:commande_confirmee,chargement_valide,transfert_cree'],
            'rules.*.recipient_type' => ['required', 'string', 'in:livreur,client'],
            'rules.*.client_type' => ['nullable', 'string', 'in:'.implode(',', ClientType::values())],
            'rules.*.channel' => ['required', 'string', 'in:sms,whatsapp'],
            'rules.*.enabled' => ['required', 'boolean'],
        ]);

        $gateways = [
            MessageChannel::SMS->value => $sms,
            MessageChannel::WHATSAPP->value => $whatsapp,
        ];

        DB::transaction(function () use ($data, $orgId, $gateways) {
            foreach ($data['rules'] as $rule) {
                $this->assertValidRuleKey($rule);

                $clientType = $rule['recipient_type'] === 'client' ? ($rule['client_type'] ?? null) : null;
                if ($rule['recipient_type'] === 'client' && $clientType === null) {
                    throw ValidationException::withMessages(['rules' => 'Un type de client est obligatoire pour une règle destinée aux clients.']);
                }

                // Défense en profondeur (cf. docblock CommunicationRuleResolver) :
                // impossible d'enregistrer une règle activée sur un canal dont le
                // fournisseur n'est pas configuré, quoi que le formulaire ait envoyé.
                $enabled = (bool) $rule['enabled'];
                if ($enabled && ! $gateways[$rule['channel']]->isConfigured()) {
                    $enabled = false;
                }

                CommunicationRule::updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'module' => $rule['module'],
                        'event' => $rule['event'],
                        'recipient_type' => $rule['recipient_type'],
                        'client_type' => $clientType,
                        'channel' => $rule['channel'],
                    ],
                    ['enabled' => $enabled],
                );
            }
        });

        return back()->with('success', 'Règles de communication mises à jour.');
    }

    /**
     * @param  array{module: string, event: string, recipient_type: string}  $rule
     */
    private function assertValidRuleKey(array $rule): void
    {
        $matches = collect(self::VALID_RULE_KEYS)->contains(
            fn (array $key) => $key['module'] === $rule['module']
                && $key['event'] === $rule['event']
                && $key['recipient_type'] === $rule['recipient_type'],
        );

        abort_unless($matches, 422, 'Combinaison événement/destinataire inconnue.');
    }
}
