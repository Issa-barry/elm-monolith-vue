<?php

namespace Tests\Unit;

use App\Contracts\WhatsAppGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Models\CommunicationRule;
use App\Models\Organization;
use App\Services\Communications\CommunicationRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\Communications\CommunicationRuleResolver — unique source de
 * vérité pour "cette règle est-elle active ?" (cf. rapport notifications de
 * commande, 07/09/2026).
 */
class CommunicationRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWhatsApp(bool $configured): WhatsAppGateway
    {
        return new class($configured) implements WhatsAppGateway
        {
            public function __construct(private readonly bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(string $phoneNumber, string $message): ?string
            {
                throw new \RuntimeException('Ne doit jamais être appelé dans ce test.');
            }
        };
    }

    private function resolver(bool $whatsappConfigured = false): CommunicationRuleResolver
    {
        $this->app->instance(WhatsAppGateway::class, $this->fakeWhatsApp($whatsappConfigured));

        return $this->app->make(CommunicationRuleResolver::class);
    }

    public function test_returns_true_when_an_enabled_sms_rule_exists(): void
    {
        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::COMMANDE_CONFIRMEE,
            'recipient_type' => CommunicationRecipientType::LIVREUR,
            'client_type' => null,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);

        $this->assertTrue($this->resolver()->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::SMS,
        ));
    }

    public function test_returns_false_when_no_rule_exists(): void
    {
        $org = Organization::factory()->create();

        $this->assertFalse($this->resolver()->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::SMS,
        ));
    }

    public function test_returns_false_when_the_rule_is_disabled(): void
    {
        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::COMMANDE_CONFIRMEE,
            'recipient_type' => CommunicationRecipientType::LIVREUR,
            'client_type' => null,
            'channel' => MessageChannel::SMS,
            'enabled' => false,
        ]);

        $this->assertFalse($this->resolver()->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::SMS,
        ));
    }

    public function test_whatsapp_rule_enabled_in_database_is_still_refused_when_the_provider_is_not_configured(): void
    {
        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::COMMANDE_CONFIRMEE,
            'recipient_type' => CommunicationRecipientType::LIVREUR,
            'client_type' => null,
            'channel' => MessageChannel::WHATSAPP,
            'enabled' => true,
        ]);

        $this->assertFalse($this->resolver(whatsappConfigured: false)->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::WHATSAPP,
        ));
    }

    public function test_whatsapp_rule_enabled_is_accepted_once_the_provider_is_configured(): void
    {
        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::COMMANDE_CONFIRMEE,
            'recipient_type' => CommunicationRecipientType::LIVREUR,
            'client_type' => null,
            'channel' => MessageChannel::WHATSAPP,
            'enabled' => true,
        ]);

        $this->assertTrue($this->resolver(whatsappConfigured: true)->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::WHATSAPP,
        ));
    }

    public function test_client_type_distinguishes_rules_externe_revendeur_distributeur_grossiste_independently(): void
    {
        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::CHARGEMENT_VALIDE,
            'recipient_type' => CommunicationRecipientType::CLIENT,
            'client_type' => ClientType::REVENDEUR,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);

        $resolver = $this->resolver();

        $this->assertTrue($resolver->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE,
            CommunicationRecipientType::CLIENT, MessageChannel::SMS, ClientType::REVENDEUR,
        ));

        foreach ([ClientType::EXTERNE, ClientType::DISTRIBUTEUR, ClientType::GROSSISTE] as $autreType) {
            $this->assertFalse($resolver->isEnabled(
                $org->id, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE,
                CommunicationRecipientType::CLIENT, MessageChannel::SMS, $autreType,
            ), "Le type {$autreType->value} ne doit pas hériter de la règle Revendeur.");
        }
    }

    public function test_a_rule_from_another_organization_is_never_matched(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $orgA->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::COMMANDE_CONFIRMEE,
            'recipient_type' => CommunicationRecipientType::LIVREUR,
            'client_type' => null,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);

        $this->assertFalse($this->resolver()->isEnabled(
            $orgB->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::SMS,
        ));
    }

    public function test_sms_and_whatsapp_are_independent_channels_on_the_same_rule(): void
    {
        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::COMMANDE_CONFIRMEE,
            'recipient_type' => CommunicationRecipientType::LIVREUR,
            'client_type' => null,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);
        // WhatsApp jamais créé pour cette règle — doit rester faux indépendamment du SMS.

        $resolver = $this->resolver(whatsappConfigured: true);

        $this->assertTrue($resolver->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::SMS,
        ));
        $this->assertFalse($resolver->isEnabled(
            $org->id, CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE,
            CommunicationRecipientType::LIVREUR, MessageChannel::WHATSAPP,
        ));
    }
}
