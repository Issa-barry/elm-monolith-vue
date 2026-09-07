<?php

namespace Tests\Unit;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Jobs\SendTransactionalCommunicationJob;
use App\Models\CommandeVente;
use App\Models\MessageLog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Jobs\SendTransactionalCommunicationJob — envoi + journalisation d'UNE
 * notification transactionnelle sur UN canal (cf. rapport notifications de
 * commande, 07/09/2026). Ne teste jamais la résolution des règles (cf.
 * CommunicationRuleResolverTest) ni la construction du texte (déjà décidées
 * en amont) : uniquement le cycle pending → sent/failed et la journalisation.
 */
class SendTransactionalCommunicationJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSmsGateway(bool $succeeds): SmsGateway
    {
        return new class($succeeds) implements SmsGateway
        {
            public int $calls = 0;

            public function __construct(private readonly bool $succeeds) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $phoneNumber, string $message): ?string
            {
                $this->calls++;

                if (! $this->succeeds) {
                    throw new \RuntimeException('Nimba : échec simulé.', 402);
                }

                return 'nimba-transactional-id';
            }
        };
    }

    private function fakeWhatsAppGateway(bool $succeeds): WhatsAppGateway
    {
        return new class($succeeds) implements WhatsAppGateway
        {
            public int $calls = 0;

            public function __construct(private readonly bool $succeeds) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $phoneNumber, string $message): ?string
            {
                $this->calls++;

                if (! $this->succeeds) {
                    throw new \RuntimeException('WhatsApp : échec simulé.');
                }

                return 'whatsapp-transactional-id';
            }
        };
    }

    public function test_a_successful_sms_send_creates_a_message_log_marked_sent(): void
    {
        $this->app->instance(SmsGateway::class, $this->fakeSmsGateway(true));
        $org = Organization::factory()->create();
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id]);

        $job = new SendTransactionalCommunicationJob(
            MessageChannel::SMS, '+224620001000', $org->id,
            CommunicationEvent::COMMANDE_CONFIRMEE, CommunicationRecipientType::LIVREUR,
            $commande->reference, $commande->getMorphClass(), $commande->id,
        );
        app()->call([$job, 'handle']);

        $log = MessageLog::sole();
        $this->assertSame('sent', $log->status->value);
        $this->assertSame('sms', $log->channel->value);
        $this->assertSame('nimba-transactional-id', $log->provider_message_id);
        $this->assertSame('commande_confirmee', $log->purpose);
        $this->assertSame('livreur', $log->recipient_type->value);
        $this->assertSame($commande->getMorphClass(), $log->messageable_type);
        $this->assertSame($commande->id, $log->messageable_id);
        $this->assertSame($org->id, $log->organization_id);
    }

    public function test_a_failed_sms_send_creates_a_message_log_marked_failed_and_never_throws(): void
    {
        $this->app->instance(SmsGateway::class, $this->fakeSmsGateway(false));
        $org = Organization::factory()->create();
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id]);

        $job = new SendTransactionalCommunicationJob(
            MessageChannel::SMS, '+224620001001', $org->id,
            CommunicationEvent::COMMANDE_CONFIRMEE, CommunicationRecipientType::LIVREUR,
            $commande->reference, $commande->getMorphClass(), $commande->id,
        );
        app()->call([$job, 'handle']);

        $log = MessageLog::sole();
        $this->assertSame('failed', $log->status->value);
        $this->assertSame('402', $log->provider_status);
        $this->assertNull($log->provider_message_id);
    }

    public function test_a_successful_whatsapp_send_creates_a_message_log_on_the_whatsapp_channel(): void
    {
        $this->app->instance(WhatsAppGateway::class, $this->fakeWhatsAppGateway(true));
        $org = Organization::factory()->create();
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id]);

        $job = new SendTransactionalCommunicationJob(
            MessageChannel::WHATSAPP, '+224620001002', $org->id,
            CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT,
            $commande->reference, $commande->getMorphClass(), $commande->id,
        );
        app()->call([$job, 'handle']);

        $log = MessageLog::sole();
        $this->assertSame('sent', $log->status->value);
        $this->assertSame('whatsapp', $log->channel->value);
        $this->assertSame('whatsapp-transactional-id', $log->provider_message_id);
        $this->assertSame('client', $log->recipient_type->value);
    }

    public function test_sms_and_whatsapp_dispatched_for_the_same_event_create_two_independent_message_logs(): void
    {
        $this->app->instance(SmsGateway::class, $this->fakeSmsGateway(true));
        $this->app->instance(WhatsAppGateway::class, $this->fakeWhatsAppGateway(true));
        $org = Organization::factory()->create();
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id]);

        app()->call([new SendTransactionalCommunicationJob(
            MessageChannel::SMS, '+224620001003', $org->id,
            CommunicationEvent::COMMANDE_CONFIRMEE, CommunicationRecipientType::LIVREUR,
            $commande->reference, $commande->getMorphClass(), $commande->id,
        ), 'handle']);

        app()->call([new SendTransactionalCommunicationJob(
            MessageChannel::WHATSAPP, '+224620001003', $org->id,
            CommunicationEvent::COMMANDE_CONFIRMEE, CommunicationRecipientType::LIVREUR,
            $commande->reference, $commande->getMorphClass(), $commande->id,
        ), 'handle']);

        $this->assertSame(2, MessageLog::count());
        $this->assertSame(
            ['sms', 'whatsapp'],
            MessageLog::orderBy('channel')->pluck('channel')->map(fn ($c) => $c->value)->all(),
        );
    }

    public function test_the_message_log_never_contains_the_message_body(): void
    {
        $this->app->instance(SmsGateway::class, $this->fakeSmsGateway(true));
        $org = Organization::factory()->create();
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id, 'reference' => 'CMD-2026-SECRET']);

        $job = new SendTransactionalCommunicationJob(
            MessageChannel::SMS, '+224620001004', $org->id,
            CommunicationEvent::COMMANDE_CONFIRMEE, CommunicationRecipientType::LIVREUR,
            $commande->reference, $commande->getMorphClass(), $commande->id,
        );
        app()->call([$job, 'handle']);

        $log = MessageLog::sole();
        $flat = json_encode($log->getAttributes());
        // La référence peut légitimement apparaître (ce n'est pas un secret) mais
        // aucune colonne "message"/"body" n'existe sur message_logs — cette
        // assertion vérifie qu'aucun texte de message n'a été ajouté par erreur.
        $this->assertArrayNotHasKey('message', $log->getAttributes());
        $this->assertArrayNotHasKey('body', $log->getAttributes());
        $this->assertStringNotContainsString('+224620001004', $flat);
    }
}
