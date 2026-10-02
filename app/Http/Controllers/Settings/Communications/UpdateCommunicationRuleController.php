<?php

namespace App\Http\Controllers\Settings\Communications;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Enums\ClientType;
use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Models\CommunicationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écran Paramètres → Communications — mise à jour. Voir EditCommunicationRuleController pour le
 * contexte complet (5 combinaisons câblées, permission `communications.manage`).
 */
class UpdateCommunicationRuleController extends Controller
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

    public function __invoke(Request $request, SmsGateway $sms, WhatsAppGateway $whatsapp): RedirectResponse
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

                // Défense en profondeur (cf. docblock EditCommunicationRuleController) :
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
