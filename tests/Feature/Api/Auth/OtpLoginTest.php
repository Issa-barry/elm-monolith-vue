<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\OtpPurpose;
use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Connexion sans mot de passe par OTP (cf. rapport du 27/08/2026) — canal
 * email aujourd'hui (seul câblé dans config('otp.channels')), sans jamais
 * marquer le téléphone vérifié : c'est exactement le scénario métier décrit
 * au point 6 du brief ("aujourd'hui l'email transporte l'OTP, demain
 * WhatsApp/SMS, sans jamais changer la logique d'authentification").
 *
 * `User::factory()` marque la nouvelle identité téléphone vérifiée par
 * défaut (comportement de fixture existant, non lié à ce chantier) — chaque
 * test qui vérifie la RÈGLE "jamais vérifié par ce parcours" part donc
 * explicitement d'un téléphone remis à `verified_at = null`.
 */
class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUnverifiedUser(string $phone, ?string $email): User
    {
        $user = User::factory()->create(['telephone' => $phone, 'email' => $email]);
        $user->telephoneIdentity()->update(['verified_at' => null, 'verification_channel' => null]);

        return $user;
    }

    public function test_request_sends_a_code_by_email_when_no_provider_is_configured(): void
    {
        Mail::fake();
        $this->makeUnverifiedUser('+224620000300', 'client@example.com');

        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224620000300'])
            ->assertOk()
            ->assertJson(['sent' => true, 'channel' => 'email']);

        Mail::assertSent(OtpCodeMail::class, fn ($mail) => $mail->purpose === OtpPurpose::LOGIN);
    }

    /**
     * destination_masked (ajouté le 27/08/2026, demande front) : la
     * coordonnée réellement utilisée, masquée côté serveur — jamais
     * l'adresse complète. Voir App\Services\Otp\OtpDestinationMasker.
     */
    public function test_request_returns_a_masked_destination(): void
    {
        Mail::fake();
        $this->makeUnverifiedUser('+224620000310', 'j.dupont@example.com');

        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224620000310'])
            ->assertOk()
            ->assertJson(['sent' => true, 'channel' => 'email', 'destination_masked' => 'j*******@example.com']);
    }

    public function test_request_returns_404_for_unknown_phone(): void
    {
        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224699999999'])
            ->assertStatus(404);
    }

    public function test_request_returns_503_when_account_has_no_email(): void
    {
        $this->makeUnverifiedUser('+224620000301', null);

        $this->postJson(route('api.auth.otp-login.request'), ['telephone' => '+224620000301'])
            ->assertStatus(503);
    }

    /**
     * Le test central du point 14 du brief : login OTP par email réussi →
     * authentification réussie, MAIS telephone.verified_at reste NULL.
     */
    public function test_verify_authenticates_via_email_delivered_code_without_verifying_the_phone(): void
    {
        $user = $this->makeUnverifiedUser('+224620000302', 'client2@example.com');

        app(OtpService::class)->generate('+224620000302', OtpPurpose::LOGIN);

        $this->postJson(route('api.auth.otp-login.verify'), [
            'telephone' => '+224620000302',
            'code' => '123456',
            'device_name' => 'test-device',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'prenom', 'nom', 'telephone', 'email', 'roles']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'test-device',
        ]);

        $user->telephoneIdentity()->refresh();
        $this->assertFalse($user->telephoneIdentity()->isVerified());
        $this->assertNull($user->telephoneIdentity()->verification_channel);
    }

    public function test_verify_fails_with_wrong_code(): void
    {
        $this->makeUnverifiedUser('+224620000303', 'client3@example.com');
        app(OtpService::class)->generate('+224620000303', OtpPurpose::LOGIN);

        $this->postJson(route('api.auth.otp-login.verify'), [
            'telephone' => '+224620000303',
            'code' => '000000',
            'device_name' => 'test-device',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'test-device']);
    }

    public function test_verify_fails_without_a_prior_request(): void
    {
        $this->makeUnverifiedUser('+224620000304', 'client4@example.com');

        $this->postJson(route('api.auth.otp-login.verify'), [
            'telephone' => '+224620000304',
            'code' => '123456',
            'device_name' => 'test-device',
        ])->assertStatus(422);
    }

    /**
     * Un compte inéligible (désactivé...) reste bloqué même avec un code
     * correct — même règle que LoginController (mot de passe), via le même
     * trait IssuesTelephoneLoginToken.
     */
    public function test_verify_rejects_an_inactive_account(): void
    {
        $user = $this->makeUnverifiedUser('+224620000305', 'client5@example.com');
        $user->update(['is_active' => false]);

        app(OtpService::class)->generate('+224620000305', OtpPurpose::LOGIN);

        $this->postJson(route('api.auth.otp-login.verify'), [
            'telephone' => '+224620000305',
            'code' => '123456',
            'device_name' => 'test-device',
        ])->assertStatus(403);
    }

    public function test_a_login_code_cannot_be_reused_as_a_phone_verification_code(): void
    {
        $this->makeUnverifiedUser('+224620000306', 'client6@example.com');
        app(OtpService::class)->generate('+224620000306', OtpPurpose::LOGIN);

        $this->assertFalse(
            app(OtpService::class)->verify('+224620000306', '123456', OtpPurpose::PHONE_VERIFICATION)
        );
    }
}
