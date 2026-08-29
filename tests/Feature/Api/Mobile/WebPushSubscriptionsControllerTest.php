<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Organization;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Web Push PWA (2026-08-28, cf. rapport) : le serveur associe TOUJOURS
 * l'abonnement au compte authentifié — jamais un user_id/organization_id
 * envoyé par le navigateur.
 */
class WebPushSubscriptionsControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function actor(): User
    {
        $org = Organization::factory()->create();

        return $this->makeProprietaireUser($org);
    }

    public function test_vapid_public_key_absent_par_defaut(): void
    {
        Sanctum::actingAs($this->actor(), ['*']);

        $this->getJson(route('client.web-push.vapid-public-key'))
            ->assertOk()
            ->assertJsonPath('public_key', null);
    }

    public function test_vapid_public_key_expose_quand_configuree(): void
    {
        config(['services.web_push.vapid_public_key' => 'test-public-key']);
        Sanctum::actingAs($this->actor(), ['*']);

        $this->getJson(route('client.web-push.vapid-public-key'))
            ->assertOk()
            ->assertJsonPath('public_key', 'test-public-key');
    }

    public function test_store_cree_un_abonnement_associe_au_user_authentifie(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user, ['*']);

        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $subscription = WebPushSubscription::first();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertNotNull($subscription->user_agent);
    }

    public function test_store_est_idempotent_pour_le_meme_endpoint(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user, ['*']);

        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ];

        $this->postJson(route('client.web-push.subscriptions.store'), $payload)->assertOk();
        $this->postJson(route('client.web-push.subscriptions.store'), $payload)->assertOk();

        $this->assertDatabaseCount('web_push_subscriptions', 1);
    }

    public function test_store_met_a_jour_les_cles_si_lendpoint_existe_deja(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user, ['*']);

        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertOk();

        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('c', 88), 'auth' => str_repeat('d', 24)],
        ])->assertOk();

        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $subscription = WebPushSubscription::first();
        $this->assertSame(str_repeat('c', 88), $subscription->p256dh);
    }

    /** Un même endpoint réabonné par un AUTRE compte (poste partagé) lui est réassigné. */
    public function test_store_reassigne_lendpoint_a_un_autre_user_si_reabonne(): void
    {
        $org = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($org);
        $userB = $this->makeLivreurUser($org);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/shared-device';

        Sanctum::actingAs($userA, ['*']);
        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertOk();

        Sanctum::actingAs($userB, ['*']);
        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertOk();

        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertSame($userB->id, WebPushSubscription::first()->user_id);
    }

    public function test_un_user_peut_avoir_plusieurs_abonnements_multi_appareils(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user, ['*']);

        foreach (['iphone', 'android', 'pc-pro'] as $device) {
            $this->postJson(route('client.web-push.subscriptions.store'), [
                'endpoint' => "https://push.example.com/{$device}",
                'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
            ])->assertOk();
        }

        $this->assertSame(3, $user->fresh()->webPushSubscriptions()->count());
    }

    public function test_destroy_supprime_uniquement_labonnement_cible(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user, ['*']);

        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => 'https://push.example.com/iphone',
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertOk();
        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => 'https://push.example.com/android',
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertOk();

        $this->deleteJson(route('client.web-push.subscriptions.destroy', ['endpoint' => 'https://push.example.com/iphone']))
            ->assertOk()->assertJsonPath('success', true);

        $remaining = $user->fresh()->webPushSubscriptions()->pluck('endpoint_hash');
        $this->assertCount(1, $remaining);
        $this->assertSame(
            [WebPushSubscription::hashEndpoint('https://push.example.com/android')],
            $remaining->all(),
        );
    }

    public function test_destroy_est_idempotent_sur_un_endpoint_deja_absent(): void
    {
        Sanctum::actingAs($this->actor(), ['*']);

        $this->deleteJson(route('client.web-push.subscriptions.destroy', ['endpoint' => 'https://push.example.com/inconnu']))
            ->assertOk()->assertJsonPath('success', true);
    }

    /** User B ne peut ni modifier (réassignation exceptée, cf. test dédié) ni supprimer l'abonnement de A via un endpoint qu'il ne possède pas — ici : suppression, cas simple d'isolation. */
    public function test_userb_ne_peut_pas_supprimer_labonnement_de_usera(): void
    {
        $org = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($org);
        $userB = $this->makeLivreurUser($org);
        $endpoint = 'https://push.example.com/de-usera';

        Sanctum::actingAs($userA, ['*']);
        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertOk();

        Sanctum::actingAs($userB, ['*']);
        $this->deleteJson(route('client.web-push.subscriptions.destroy', ['endpoint' => $endpoint]))
            ->assertOk()->assertJsonPath('success', true);

        // Toujours là, toujours à User A : la tentative de User B n'a rien changé.
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertSame($userA->id, WebPushSubscription::first()->user_id);
    }

    public function test_validation_rejette_un_payload_incomplet(): void
    {
        Sanctum::actingAs($this->actor(), ['*']);

        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => 'https://push.example.com/x',
        ])->assertStatus(422);

        $this->postJson(route('client.web-push.subscriptions.store'), [
            'keys' => ['p256dh' => 'a', 'auth' => 'b'],
        ])->assertStatus(422);
    }

    public function test_validation_rejette_un_endpoint_trop_long(): void
    {
        Sanctum::actingAs($this->actor(), ['*']);

        $this->postJson(route('client.web-push.subscriptions.store'), [
            'endpoint' => 'https://push.example.com/'.str_repeat('x', 2100),
            'keys' => ['p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24)],
        ])->assertStatus(422);
    }
}
