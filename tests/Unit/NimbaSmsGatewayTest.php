<?php

namespace Tests\Unit;

use App\Exceptions\NimbaSmsException;
use App\Services\Sms\NimbaSmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Fournisseur SMS Nimba (cf. audit du 31/08/2026, intégration Nimba SMS) —
 * `Http::fake()` uniquement : ces tests ne doivent JAMAIS atteindre le vrai
 * `https://api.nimbasms.com` ni envoyer un SMS réel.
 */
class NimbaSmsGatewayTest extends TestCase
{
    private function configureNimba(): void
    {
        config([
            'services.nimba_sms.service_id' => 'test-service-id',
            'services.nimba_sms.secret_token' => 'test-secret-token',
            'services.nimba_sms.sender_name' => 'EAULAMAMAN',
        ]);
    }

    private function gateway(): NimbaSmsGateway
    {
        return app(NimbaSmsGateway::class);
    }

    public function test_is_configured_false_when_any_credential_is_missing(): void
    {
        config(['services.nimba_sms' => ['service_id' => null, 'secret_token' => null, 'sender_name' => null]]);
        $this->assertFalse($this->gateway()->isConfigured());

        config(['services.nimba_sms' => ['service_id' => 'x', 'secret_token' => null, 'sender_name' => 'EAULAMAMAN']]);
        $this->assertFalse($this->gateway()->isConfigured());
    }

    public function test_send_throws_and_makes_no_http_call_when_not_configured(): void
    {
        Http::fake();
        config(['services.nimba_sms' => ['service_id' => null, 'secret_token' => null, 'sender_name' => null]]);

        $this->expectException(NimbaSmsException::class);

        try {
            $this->gateway()->send('+224620000700', 'Votre code Eau La Maman est : 123456. Il expire dans 10 minutes.');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_send_calls_nimba_messages_endpoint_with_basic_auth_recipient_sender_and_message(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['message_id' => 'abc'], 200)]);

        $this->gateway()->send('+224620000701', 'Votre code Eau La Maman est : 123456. Il expire dans 10 minutes.');

        Http::assertSent(function (Request $request) {
            $auth = $request->header('Authorization')[0] ?? '';
            $expectedAuth = 'Basic '.base64_encode('test-service-id:test-secret-token');

            return $request->url() === 'https://api.nimbasms.com/v1/messages'
                && $request->method() === 'POST'
                && $auth === $expectedAuth
                // Contrat Nimba : `to` est TOUJOURS un tableau (1 à 30 chaînes),
                // même pour un seul destinataire — jamais une valeur scalaire.
                && $request->data()['to'] === ['+224620000701']
                && $request->data()['sender_name'] === 'EAULAMAMAN'
                && $request->data()['message'] === 'Votre code Eau La Maman est : 123456. Il expire dans 10 minutes.'
                && $request->data()['channel'] === 'sms';
        });
    }

    public function test_send_succeeds_silently_on_2xx_response(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['message_id' => 'abc'], 201)]);

        $this->gateway()->send('+224620000702', 'Votre code Eau La Maman est : 111111. Il expire dans 10 minutes.');

        Http::assertSentCount(1);
    }

    public function test_send_throws_on_402_insufficient_balance(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['error' => 'Solde insuffisant'], 402)]);

        $this->expectException(NimbaSmsException::class);

        $this->gateway()->send('+224620000703', 'Votre code Eau La Maman est : 222222. Il expire dans 10 minutes.');
    }

    public function test_send_throws_on_429_rate_limited(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['error' => 'Too many requests'], 429)]);

        $this->expectException(NimbaSmsException::class);

        $this->gateway()->send('+224620000706', 'Votre code Eau La Maman est : 555555. Il expire dans 10 minutes.');
    }

    public function test_send_throws_on_500_server_error(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['error' => 'Internal error'], 500)]);

        $this->expectException(NimbaSmsException::class);

        $this->gateway()->send('+224620000707', 'Votre code Eau La Maman est : 666666. Il expire dans 10 minutes.');
    }

    public function test_send_throws_on_sender_name_rejected(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['error' => 'Sender name non validé'], 400)]);

        $this->expectException(NimbaSmsException::class);

        $this->gateway()->send('+224620000708', 'Votre code Eau La Maman est : 777777. Il expire dans 10 minutes.');
    }

    public function test_send_throws_on_connection_timeout(): void
    {
        $this->configureNimba();
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(NimbaSmsException::class);

        $this->gateway()->send('+224620000704', 'Votre code Eau La Maman est : 333333. Il expire dans 10 minutes.');
    }

    public function test_failure_logs_never_contain_the_secret_token_or_the_otp_code(): void
    {
        $this->configureNimba();
        $message = 'Votre code Eau La Maman est : 444444. Il expire dans 10 minutes.';
        // Le corps d'erreur simulé échoïe volontairement le message soumis
        // (certaines APIs renvoient les champs de la requête invalide) — le
        // test prouve que la rédaction s'applique bien avant journalisation.
        Http::fake(['api.nimbasms.com/*' => Http::response(['message' => $message, 'error' => 'Sender name invalide'], 400)]);

        Log::spy();

        try {
            $this->gateway()->send('+224620000705', $message);
        } catch (NimbaSmsException) {
            // attendu
        }

        Log::shouldHaveReceived('error')->withArgs(function (string $logMessage, array $context) use ($message) {
            $flat = $logMessage.' '.json_encode($context);

            $this->assertStringNotContainsString('test-secret-token', $flat);
            $this->assertStringNotContainsString($message, $flat);
            $this->assertStringNotContainsString('444444', $flat);

            return true;
        });
    }
}
