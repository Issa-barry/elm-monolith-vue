<?php

namespace Tests\Feature\Notification;

use App\Models\Organization;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Notification\WebPushService;
use Base64Url\Base64Url;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Jose\Component\KeyManagement\JWKFactory;
use Minishlink\WebPush\Utils;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

/**
 * Aucun réseau réel : le client PSR-18 passé à WebPushService est un Guzzle
 * dont le handler est un `MockHandler` (cf. rapport Web Push, 2026-08-28).
 * Couvre spécifiquement le point le plus sensible : suppression automatique
 * d'un abonnement expiré (404/410 — `isSubscriptionExpired()`, natif de la
 * lib) vs conservation sur échec temporaire.
 */
class WebPushServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $vapid = VAPID::createVapidKeys();
        config([
            'services.web_push.vapid_public_key' => $vapid['publicKey'],
            'services.web_push.vapid_private_key' => $vapid['privateKey'],
            'services.web_push.vapid_subject' => 'mailto:test@example.com',
        ]);
    }

    /** Clé EC P-256 valide (même format qu'un vrai p256dh de navigateur) — jamais une chaîne arbitraire, l'encryption réelle de la lib l'exigerait. */
    private function makeSubscriberKeys(): array
    {
        $jwk = JWKFactory::createECKey('P-256');
        $binaryPublicKey = hex2bin(Utils::serializePublicKeyFromJWK($jwk));

        return [
            'p256dh' => Base64Url::encode($binaryPublicKey),
            'auth' => Base64Url::encode(random_bytes(16)),
        ];
    }

    private function makeSubscription(User $user, string $endpoint = 'https://push.example.com/ep'): WebPushSubscription
    {
        $keys = $this->makeSubscriberKeys();

        return WebPushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => WebPushSubscription::hashEndpoint($endpoint),
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
            'content_encoding' => 'aes128gcm',
        ]);
    }

    private function serviceWithMockedResponses(array $responses): WebPushService
    {
        $handler = HandlerStack::create(new MockHandler($responses));

        return new WebPushService(new Client(['handler' => $handler]));
    }

    private function makeUser(): User
    {
        return User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    }

    public function test_envoi_reussi_met_a_jour_last_used_at(): void
    {
        $user = $this->makeUser();
        $subscription = $this->makeSubscription($user);

        $this->serviceWithMockedResponses([new Response(201)])
            ->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertNotNull($subscription->fresh()->last_used_at);
    }

    public function test_abonnement_expire_410_est_supprime(): void
    {
        $user = $this->makeUser();
        $subscription = $this->makeSubscription($user);

        $this->serviceWithMockedResponses([new Response(410)])
            ->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertNull($subscription->fresh());
    }

    public function test_abonnement_expire_404_est_supprime(): void
    {
        $user = $this->makeUser();
        $subscription = $this->makeSubscription($user);

        $this->serviceWithMockedResponses([new Response(404)])
            ->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertNull($subscription->fresh());
    }

    public function test_echec_temporaire_conserve_labonnement(): void
    {
        $user = $this->makeUser();
        $subscription = $this->makeSubscription($user);

        $this->serviceWithMockedResponses([new Response(500)])
            ->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertNotNull($subscription->fresh());
        $this->assertNull($subscription->fresh()->last_used_at);
    }

    public function test_user_sans_abonnement_ne_leve_aucune_erreur(): void
    {
        $user = $this->makeUser();

        $this->serviceWithMockedResponses([])->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertTrue(true);
    }

    public function test_vapid_non_configure_ne_leve_aucune_erreur_et_nenvoie_rien(): void
    {
        config([
            'services.web_push.vapid_public_key' => null,
            'services.web_push.vapid_private_key' => null,
        ]);

        $user = $this->makeUser();
        $subscription = $this->makeSubscription($user);

        // Aucune réponse mockée fournie : une tentative d'envoi ferait échouer le test
        // (MockHandler vide lève une exception) — la preuve que rien n'a été envoyé.
        $this->serviceWithMockedResponses([])->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertNull($subscription->fresh()->last_used_at);
    }

    public function test_plusieurs_abonnements_recoivent_chacun_lenvoi(): void
    {
        $user = $this->makeUser();
        $subA = $this->makeSubscription($user, 'https://push.example.com/a');
        $subB = $this->makeSubscription($user, 'https://push.example.com/b');

        $this->serviceWithMockedResponses([new Response(201), new Response(201)])
            ->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertNotNull($subA->fresh()->last_used_at);
        $this->assertNotNull($subB->fresh()->last_used_at);
    }
}
