<?php

namespace Tests\Feature\Jobs;

use App\Contracts\SmsGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Enums\StatutTransfert;
use App\Jobs\NotifierChargementValideTransfertJob;
use App\Models\CommunicationRule;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Jobs\NotifierChargementValideTransfertJob — événement
 * `chargement_valide` (Logistique). Livreur UNIQUEMENT : jamais de client,
 * App\Models\TransfertLogistique n'en a aucun (cf. rapport notifications de
 * commande, 07/09/2026).
 */
class NotifierChargementValideTransfertJobTest extends TestCase
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

    /** @return array{0: TransfertLogistique, 1: Livreur} */
    private function makeTransfertAvecEquipe(Organization $org): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id, 'proprietaire_id' => $proprietaire->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id, 'telephone' => '+224620004000']);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Transfert',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);

        $siteA = Site::create(['organization_id' => $org->id, 'nom' => 'Site A', 'type' => 'depot', 'localisation' => 'Test']);
        $siteB = Site::create(['organization_id' => $org->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Test']);
        $createur = User::factory()->create(['organization_id' => $org->id]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $org->id,
            'site_source_id' => $siteA->id,
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
            'equipe_livraison_id' => $equipe->id,
            'statut' => StatutTransfert::TRANSIT,
            'created_by' => $createur->id,
        ]);

        return [$transfert, $livreur];
    }

    public function test_notifies_the_livreur_by_sms_when_the_rule_is_enabled(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::LOGISTIQUE,
            'event' => CommunicationEvent::CHARGEMENT_VALIDE,
            'recipient_type' => CommunicationRecipientType::LIVREUR,
            'client_type' => null,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);
        [$transfert, $livreur] = $this->makeTransfertAvecEquipe($org);

        app()->call([new NotifierChargementValideTransfertJob($transfert->id, $transfert->reference), 'handle']);

        $this->assertSame([$livreur->telephone], $gateway->sentTo);
        $log = MessageLog::sole();
        $this->assertSame('chargement_valide', $log->purpose);
        $this->assertSame('livreur', $log->recipient_type->value);
        $this->assertSame($transfert->getMorphClass(), $log->messageable_type);
    }

    public function test_does_not_notify_when_the_rule_is_disabled(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        [$transfert] = $this->makeTransfertAvecEquipe($org);

        app()->call([new NotifierChargementValideTransfertJob($transfert->id, $transfert->reference), 'handle']);

        $this->assertEmpty($gateway->sentTo);
        $this->assertSame(0, MessageLog::count());
    }

    /**
     * Confirme structurellement qu'aucune règle "client" n'existe jamais pour
     * la Logistique : même si une règle client était insérée directement en
     * base pour ce module (ce que l'écran de paramétrage empêche déjà), le
     * job ne consulte jamais de client — TransfertLogistique n'en a aucun.
     */
    public function test_ventes_client_rule_never_leaks_into_a_logistique_notification(): void
    {
        $gateway = $this->fakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        CommunicationRule::create([
            'organization_id' => $org->id,
            'module' => CommunicationModule::VENTES,
            'event' => CommunicationEvent::CHARGEMENT_VALIDE,
            'recipient_type' => CommunicationRecipientType::CLIENT,
            'client_type' => ClientType::EXTERNE,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);
        [$transfert] = $this->makeTransfertAvecEquipe($org);

        app()->call([new NotifierChargementValideTransfertJob($transfert->id, $transfert->reference), 'handle']);

        $this->assertEmpty($gateway->sentTo);
    }
}
