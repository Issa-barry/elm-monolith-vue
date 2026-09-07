<?php

namespace Tests\Feature\Settings;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Models\CommunicationRule;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * App\Http\Controllers\Settings\CommunicationRuleController — écran
 * Paramètres → Communications (cf. rapport notifications de commande,
 * 07/09/2026). Permission dédiée `communications.manage`, distincte de
 * `communications.read` (monitoring).
 */
class CommunicationRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SMS "configuré" par défaut dans cette classe (Nimba n'a pas d'identifiants
     * en environnement de test — cf. NimbaSmsGatewayTest) : ces tests portent
     * sur la persistance des règles, pas sur la disponibilité réelle du
     * fournisseur SMS (déjà couverte ailleurs). Seul WhatsApp est volontairement
     * varié test par test (c'est ce que ce fichier vérifie).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, $this->fakeSms(true));
    }

    private function fakeSms(bool $configured): SmsGateway
    {
        return new class($configured) implements SmsGateway
        {
            public function __construct(private readonly bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(string $phoneNumber, string $message): ?string
            {
                throw new \RuntimeException('Ne doit jamais être appelé.');
            }
        };
    }

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
                throw new \RuntimeException('Ne doit jamais être appelé.');
            }
        };
    }

    private function makeUser(Organization $org, array $permissions): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, []);

        $this->actingAs($user)->get('/settings/communications')->assertStatus(403);
    }

    /** communications.read (monitoring) ne suffit jamais pour gérer les règles. */
    public function test_communications_read_alone_does_not_grant_access_to_settings(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.read']);

        $this->actingAs($user)->get('/settings/communications')->assertStatus(403);
    }

    public function test_a_user_with_the_permission_can_view_the_page(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        $response = $this->actingAs($user)->get('/settings/communications');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('settings/Communications'));
    }

    public function test_channel_availability_reflects_the_whatsapp_gateway_configuration(): void
    {
        $this->app->instance(WhatsAppGateway::class, $this->fakeWhatsApp(false));
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        $response = $this->actingAs($user)->get('/settings/communications');

        $response->assertInertia(fn ($page) => $page
            ->where('channel_availability.sms', true)
            ->where('channel_availability.whatsapp', false));
    }

    public function test_update_persists_an_enabled_sms_rule(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        $this->actingAs($user)->put('/settings/communications', [
            'rules' => [
                ['module' => 'ventes', 'event' => 'commande_confirmee', 'recipient_type' => 'livreur', 'client_type' => null, 'channel' => 'sms', 'enabled' => true],
            ],
        ])->assertRedirect();

        $rule = CommunicationRule::sole();
        $this->assertTrue($rule->enabled);
        $this->assertSame($org->id, $rule->organization_id);
        $this->assertSame(CommunicationModule::VENTES, $rule->module);
        $this->assertSame(CommunicationRecipientType::LIVREUR, $rule->recipient_type);
        $this->assertNull($rule->client_type);
    }

    public function test_update_persists_a_client_rule_with_its_client_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        $this->actingAs($user)->put('/settings/communications', [
            'rules' => [
                ['module' => 'ventes', 'event' => 'chargement_valide', 'recipient_type' => 'client', 'client_type' => 'grossiste', 'channel' => 'sms', 'enabled' => true],
            ],
        ])->assertRedirect();

        $rule = CommunicationRule::sole();
        $this->assertSame(ClientType::GROSSISTE, $rule->client_type);
    }

    public function test_update_toggling_a_rule_off_updates_the_existing_row_instead_of_duplicating(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        $payload = ['module' => 'ventes', 'event' => 'commande_confirmee', 'recipient_type' => 'livreur', 'client_type' => null, 'channel' => 'sms'];

        $this->actingAs($user)->put('/settings/communications', ['rules' => [[...$payload, 'enabled' => true]]]);
        $this->actingAs($user)->put('/settings/communications', ['rules' => [[...$payload, 'enabled' => false]]]);

        $this->assertSame(1, CommunicationRule::count());
        $this->assertFalse(CommunicationRule::sole()->enabled);
    }

    /**
     * Défense en profondeur (cf. docblock CommunicationRuleResolver) : même si
     * le formulaire envoyait `enabled: true` sur WhatsApp, le backend ne doit
     * jamais l'enregistrer tel quel tant que le fournisseur n'est pas
     * configuré.
     */
    public function test_update_forces_a_whatsapp_rule_to_disabled_when_the_provider_is_not_configured(): void
    {
        $this->app->instance(WhatsAppGateway::class, $this->fakeWhatsApp(false));
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        $this->actingAs($user)->put('/settings/communications', [
            'rules' => [
                ['module' => 'ventes', 'event' => 'commande_confirmee', 'recipient_type' => 'livreur', 'client_type' => null, 'channel' => 'whatsapp', 'enabled' => true],
            ],
        ])->assertRedirect();

        $rule = CommunicationRule::sole();
        $this->assertFalse($rule->enabled);
    }

    public function test_update_allows_an_enabled_whatsapp_rule_once_the_provider_is_configured(): void
    {
        $this->app->instance(WhatsAppGateway::class, $this->fakeWhatsApp(true));
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        $this->actingAs($user)->put('/settings/communications', [
            'rules' => [
                ['module' => 'ventes', 'event' => 'commande_confirmee', 'recipient_type' => 'livreur', 'client_type' => null, 'channel' => 'whatsapp', 'enabled' => true],
            ],
        ])->assertRedirect();

        $this->assertTrue(CommunicationRule::sole()->enabled);
    }

    public function test_update_rejects_an_unknown_module_event_recipient_combination(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.manage']);

        // "Client à la création" n'est pas une combinaison valide (cf. rapport,
        // point 11 : uniquement chargement_valide côté Ventes).
        $this->actingAs($user)->put('/settings/communications', [
            'rules' => [
                ['module' => 'ventes', 'event' => 'commande_confirmee', 'recipient_type' => 'client', 'client_type' => 'externe', 'channel' => 'sms', 'enabled' => true],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, CommunicationRule::count());
    }

    public function test_a_rule_created_by_one_organization_is_never_visible_to_another(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->makeUser($orgA, ['communications.manage']);
        $userB = $this->makeUser($orgB, ['communications.manage']);

        $this->actingAs($userA)->put('/settings/communications', [
            'rules' => [
                ['module' => 'ventes', 'event' => 'commande_confirmee', 'recipient_type' => 'livreur', 'client_type' => null, 'channel' => 'sms', 'enabled' => true],
            ],
        ]);

        $response = $this->actingAs($userB)->get('/settings/communications');

        $response->assertInertia(fn ($page) => $page
            ->where('ventes.commande_confirmee.livreur.sms', false));
    }
}
