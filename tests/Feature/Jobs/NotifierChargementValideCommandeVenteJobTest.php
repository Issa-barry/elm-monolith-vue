<?php

namespace Tests\Feature\Jobs;

use App\Contracts\SmsGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Jobs\NotifierChargementValideCommandeVenteJob;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommunicationRule;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Jobs\NotifierChargementValideCommandeVenteJob — événement
 * `chargement_valide` (Ventes), SEUL point qui notifie un CLIENT (cf. rapport
 * notifications de commande, 07/09/2026). Livreur ET client peuvent recevoir
 * un SMS/WhatsApp SANS compte User (cf. App\Services\Communications\
 * TransactionalCommunicationDispatcher, résolution directe du téléphone).
 */
class NotifierChargementValideCommandeVenteJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSmsGateway(): SmsGateway
    {
        return new class implements SmsGateway
        {
            public array $sentTo = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $phoneNumber, string $message): ?string
            {
                $this->sentTo[] = $phoneNumber;

                return 'fake-id';
            }
        };
    }

    private function enableRule(Organization $org, CommunicationModule $module, CommunicationEvent $event, CommunicationRecipientType $recipient, ?ClientType $clientType = null): void
    {
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => $module,
            'event' => $event,
            'recipient_type' => $recipient,
            'client_type' => $clientType,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);
    }

    /** @return array{0: CommandeVente, 1: Livreur} */
    private function makeCommandeAvecEquipe(Organization $org, ?Client $client = null): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id, 'proprietaire_id' => $proprietaire->id]);
        // Livreur SANS compte User — prouve que le SMS ne dépend jamais d'un compte applicatif.
        $livreur = Livreur::factory()->create(['organization_id' => $org->id, 'telephone' => '+224620002000']);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Site Test', 'type' => 'depot', 'localisation' => 'Test']);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'client_id' => $client?->id,
        ]);

        return [$commande, $livreur];
    }

    public function test_notifies_the_livreur_by_sms_when_the_rule_is_enabled(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        $this->enableRule($org, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::LIVREUR);
        [$commande, $livreur] = $this->makeCommandeAvecEquipe($org);

        app()->call([new NotifierChargementValideCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertSame([$livreur->telephone], $gateway->sentTo);
        $this->assertSame(1, MessageLog::count());
        $this->assertSame('chargement_valide', MessageLog::sole()->purpose);
    }

    public function test_does_not_notify_the_livreur_when_the_rule_is_disabled(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        // Aucune règle activée.
        [$commande] = $this->makeCommandeAvecEquipe($org);

        app()->call([new NotifierChargementValideCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertEmpty($gateway->sentTo);
        $this->assertSame(0, MessageLog::count());
    }

    public function test_notifies_the_client_by_sms_respecting_its_client_type(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        $this->enableRule($org, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, ClientType::REVENDEUR);
        // Client SANS compte User (user_id null par défaut, cf. ClientFactory).
        $client = Client::factory()->create(['organization_id' => $org->id, 'type' => ClientType::REVENDEUR, 'telephone' => '+224620003000']);
        [$commande] = $this->makeCommandeAvecEquipe($org, $client);

        app()->call([new NotifierChargementValideCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertSame(['+224620003000'], $gateway->sentTo);
        $log = MessageLog::sole();
        $this->assertSame('client', $log->recipient_type->value);
    }

    public function test_does_not_notify_a_client_of_a_different_type_than_the_enabled_rule(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        $this->enableRule($org, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, ClientType::REVENDEUR);
        // Client Distributeur — la règle activée ne concerne que Revendeur.
        $client = Client::factory()->create(['organization_id' => $org->id, 'type' => ClientType::DISTRIBUTEUR, 'telephone' => '+224620003001']);
        [$commande] = $this->makeCommandeAvecEquipe($org, $client);

        app()->call([new NotifierChargementValideCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertEmpty($gateway->sentTo);
    }

    public function test_does_not_notify_when_there_is_no_client_on_the_commande(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        $this->enableRule($org, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, ClientType::REVENDEUR);
        [$commande] = $this->makeCommandeAvecEquipe($org, client: null);

        app()->call([new NotifierChargementValideCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertEmpty($gateway->sentTo);
    }

    public function test_does_not_notify_a_client_without_a_phone_number(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        $this->enableRule($org, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, ClientType::REVENDEUR);
        $client = Client::factory()->create(['organization_id' => $org->id, 'type' => ClientType::REVENDEUR, 'telephone' => null]);
        [$commande] = $this->makeCommandeAvecEquipe($org, $client);

        // Aucune exception, même sans téléphone.
        app()->call([new NotifierChargementValideCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertEmpty($gateway->sentTo);
        $this->assertTrue(true);
    }

    public function test_livreur_and_client_rules_both_enabled_notify_both_independently(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        $this->enableRule($org, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::LIVREUR);
        $this->enableRule($org, CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, ClientType::EXTERNE);
        $client = Client::factory()->create(['organization_id' => $org->id, 'type' => ClientType::EXTERNE, 'telephone' => '+224620003002']);
        [$commande, $livreur] = $this->makeCommandeAvecEquipe($org, $client);

        app()->call([new NotifierChargementValideCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertCount(2, $gateway->sentTo);
        $this->assertContains($livreur->telephone, $gateway->sentTo);
        $this->assertContains('+224620003002', $gateway->sentTo);
        $this->assertSame(2, MessageLog::count());
    }
}
