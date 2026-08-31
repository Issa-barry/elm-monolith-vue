<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Connexion OTP par SMS via Nimba (audit du 31/08/2026, cf. OtpLoginTest pour
 * le parcours email déjà couvert). `sms` est désormais déclaré dans
 * config('otp.channels') par défaut (config/otp.php) — ces tests configurent
 * explicitement les identifiants Nimba pour rendre le canal RÉELLEMENT
 * disponible (SmsOtpChannel::isAvailable()), sinon la résolution retombe sur
 * email comme avant (cf. test_falls_back_to_email... ci-dessous).
 *
 * `Http::fake()` partout : ne doit jamais atteindre le vrai
 * https://api.nimbasms.com ni envoyer un SMS réel.
 */
class OtpLoginNimbaSmsTest extends TestCase
{
    use RefreshDatabase;

    private function configureNimba(): void
    {
        config([
            'services.nimba_sms.service_id' => 'test-service-id',
            'services.nimba_sms.secret_token' => 'test-secret-token',
            'services.nimba_sms.sender_name' => 'EAULAMAMAN',
        ]);
    }

    private function makeUnverifiedUser(string $phone, ?string $email): User
    {
        $user = User::factory()->create(['telephone' => $phone, 'email' => $email]);
        $user->telephoneIdentity()->update(['verified_at' => null, 'verification_channel' => null]);

        return $user;
    }

    public function test_request_uses_sms_via_nimba_once_configured_even_when_the_account_has_an_email(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['message_id' => 'abc'], 200)]);
        $this->makeUnverifiedUser('+224620000800', 'client@example.com');

        // whatsapp n'est jamais configuré : sms (en tête de liste juste après)
        // devient le canal réellement utilisé, même si l'email existe aussi.
        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224620000800'])
            ->assertOk()
            ->assertJson(['sent' => true, 'channel' => 'sms']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.nimbasms.com/v1/messages'
            && $request->data()['to'] === '+224620000800'
            && $request->data()['sender_name'] === 'EAULAMAMAN'
            && str_contains($request->data()['message'], '123456') // OTP_FIXED_CODE (.env.testing)
        );
    }

    public function test_request_falls_back_to_email_when_nimba_is_not_configured(): void
    {
        // Pas de configureNimba() ici : NIMBA_SMS_* absents en environnement
        // de test (.env.testing) -> SmsOtpChannel::isAvailable() est faux,
        // la résolution retombe sur email exactement comme avant l'intégration
        // Nimba (cf. OtpLoginTest::test_request_sends_a_code_by_email_...).
        Http::fake();
        $this->makeUnverifiedUser('+224620000801', 'client2@example.com');

        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224620000801'])
            ->assertOk()
            ->assertJson(['sent' => true, 'channel' => 'email']);

        Http::assertNothingSent();
    }

    public function test_login_still_succeeds_after_requesting_a_code_by_sms(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['message_id' => 'abc'], 200)]);
        $this->makeUnverifiedUser('+224620000802', 'client3@example.com');

        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224620000802'])
            ->assertOk()
            ->assertJson(['channel' => 'sms']);

        $this->postJson(route('api.auth.otp-login.verify'), [
            'telephone' => '+224620000802',
            'code' => '123456',
            'device_name' => 'test-device',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id']]);
    }

    /**
     * Une panne Nimba (solde insuffisant, sender name refusé...) ne doit
     * jamais faire échouer la réponse HTTP au client — cf. docblock
     * SendSmsOtpJob : le challenge OTP est déjà généré, l'échec de transport
     * est journalisé, jamais renvoyé au contrôleur (qui a déjà répondu).
     */
    public function test_request_still_returns_200_when_nimba_call_fails(): void
    {
        $this->configureNimba();
        Http::fake(['api.nimbasms.com/*' => Http::response(['error' => 'Solde insuffisant'], 402)]);
        $this->makeUnverifiedUser('+224620000803', 'client4@example.com');

        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224620000803'])
            ->assertOk()
            ->assertJson(['sent' => true, 'channel' => 'sms']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.nimbasms.com/v1/messages');
    }
}
