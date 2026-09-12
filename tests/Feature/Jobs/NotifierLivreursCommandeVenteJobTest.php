<?php

namespace Tests\Feature\Jobs;

use App\Contracts\SmsGateway;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Enums\StatutCommandeVente;
use App\Jobs\NotifierLivreursCommandeVenteJob;
use App\Models\CommandeVente;
use App\Models\CommunicationRule;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\Site;
use App\Models\Vehicule;
use App\Notifications\CommandeValideeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Phase 1 archi notifications (2026-08-27, cf. rapport) : le propriétaire du
 * véhicule ne reçoit PLUS cette notification — décision produit explicite
 * (affectation logistique, pas financière ; cf.
 * CommissionGenereeNotificationTest pour ce qui le concerne réellement).
 * Régression du 26/08/2026 conservée : ce job est le point d'envoi réel de la
 * catégorie de préférence `livraisons` (ex-`activite`).
 */
class NotifierLivreursCommandeVenteJobTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function makeCommandeAvecEquipe(Organization $org, ?array $livreurPrefs = null): array
    {
        $proprietaireUser = $this->makeProprietaireUser($org);
        $livreurUser = $this->makeLivreurUser($org);
        if ($livreurPrefs !== null) {
            $livreurUser->update(['notification_preferences' => $livreurPrefs]);
        }
        $livreurUser->forceFill(['expo_push_token' => 'ExponentPushToken[livreur]'])->save();

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireUser->proprietaire->id,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Test',
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
        ]);

        return [$commande, $livreurUser, $proprietaireUser, $equipe];
    }

    public function test_notifies_and_pushes_livreur_but_not_proprietaire(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$commande, $livreurUser, $proprietaireUser] = $this->makeCommandeAvecEquipe($org);

        app()->call([new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        Notification::assertSentTo($livreurUser, CommandeValideeNotification::class);
        Notification::assertNotSentTo($proprietaireUser, CommandeValideeNotification::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && in_array('ExponentPushToken[livreur]', collect($request->data())->pluck('to')->all(), true));
    }

    public function test_skips_livreur_who_disabled_livraisons_preference(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$commande, $livreurUser] = $this->makeCommandeAvecEquipe($org, livreurPrefs: ['livraisons' => false]);

        app()->call([new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        Notification::assertNotSentTo($livreurUser, CommandeValideeNotification::class);
        Http::assertNothingSent();
    }

    /** Compatibilité ascendante : ancien réglage activite=false sans clé fine reste respecté. */
    public function test_skips_livreur_who_disabled_legacy_activite_preference(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$commande, $livreurUser] = $this->makeCommandeAvecEquipe($org, livreurPrefs: ['activite' => false]);

        app()->call([new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        Notification::assertNotSentTo($livreurUser, CommandeValideeNotification::class);
    }

    public function test_does_nothing_when_commande_has_no_vehicule(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => null,
            'statut' => StatutCommandeVente::BROUILLON->value,
        ]);

        app()->call([new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    // Note : la déduplication du NotificationDispatcher (même User résolu deux
    // fois pour un même envoi) est couverte directement et unitairement par
    // NotificationDispatcherTest — equipe_livreurs a une contrainte UNIQUE sur
    // livreur_id qui empêche de reproduire un doublon de pivot ici.

    // ── Notifications transactionnelles SMS/WhatsApp (07/09/2026) ──────────────

    public function test_also_sends_a_transactional_sms_to_the_livreur_when_the_rule_is_enabled(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $gateway = new class implements SmsGateway
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
        $this->app->instance(SmsGateway::class, $gateway);

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
        [$commande, $livreurUser] = $this->makeCommandeAvecEquipe($org);

        app()->call([new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertSame([$livreurUser->telephone], $gateway->sentTo);
        $log = MessageLog::sole();
        $this->assertSame('commande_confirmee', $log->purpose);
        $this->assertSame($commande->getMorphClass(), $log->messageable_type);
    }

    public function test_does_not_send_a_transactional_sms_when_no_rule_is_configured(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $gateway = new class implements SmsGateway
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
        $this->app->instance(SmsGateway::class, $gateway);

        $org = Organization::factory()->create();
        [$commande] = $this->makeCommandeAvecEquipe($org);

        app()->call([new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference), 'handle']);

        $this->assertEmpty($gateway->sentTo);
        $this->assertSame(0, MessageLog::count());
    }
}
