<?php

namespace Tests\Feature;

use App\Enums\DomaineActivite;
use App\Mail\InstallEmailVerificationMail;
use App\Models\AppInstallation;
use App\Services\InstallationService;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Vérification par code de l'email du Super Admin pendant /install — facultative (l'email
 * lui-même reste facultatif), mais réelle : "email saisi ≠ email vérifié" tant que le code n'a
 * pas été validé (cf. InstallationService::install(), qui lit ce statut via OtpService).
 *
 * `.env.testing` fixe OTP_FIXED_CODE=123456 (cf. config/otp.php) : chaque code généré en test
 * vaut donc toujours "123456", ce qui rend ces tests déterministes sans lire le contenu du mail.
 */
class InstallEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'issa@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_envoi_dun_code_dispatch_le_mail_et_reinitialise_les_tentatives(): void
    {
        Mail::fake();

        $this->postJson('/install/email/send-code', ['email' => self::EMAIL])
            ->assertOk()
            ->assertJson(['sent' => true]);

        Mail::assertSent(InstallEmailVerificationMail::class, fn ($mail) => $mail->code === '123456');
    }

    public function test_email_invalide_est_rejete(): void
    {
        Mail::fake();

        $this->postJson('/install/email/send-code', ['email' => 'pas-un-email'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_code_correct_marque_lemail_verifie(): void
    {
        Mail::fake();
        app(OtpService::class)->generate(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);

        $this->postJson('/install/email/verify-code', ['email' => self::EMAIL, 'code' => '123456'])
            ->assertOk()
            ->assertJson(['verified' => true]);

        $this->assertTrue(app(OtpService::class)->isVerified(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT));
    }

    public function test_code_incorrect_est_refuse(): void
    {
        app(OtpService::class)->generate(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);

        $this->postJson('/install/email/verify-code', ['email' => self::EMAIL, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'invalid']);

        $this->assertFalse(app(OtpService::class)->isVerified(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT));
    }

    public function test_verifier_sans_code_envoye_au_prealable_echoue(): void
    {
        $this->postJson('/install/email/verify-code', ['email' => self::EMAIL, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'expired']);
    }

    public function test_trop_de_tentatives_verrouille_le_code(): void
    {
        app(OtpService::class)->generate(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/install/email/verify-code', ['email' => self::EMAIL, 'code' => '000000']);
        }

        $this->postJson('/install/email/verify-code', ['email' => self::EMAIL, 'code' => '123456'])
            ->assertStatus(429)
            ->assertJson(['reason' => 'locked']);
    }

    public function test_renvoi_immediat_est_soumis_au_delai_anti_spam(): void
    {
        Mail::fake();
        app(OtpService::class)->generate(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);

        $this->postJson('/install/email/send-code', ['email' => self::EMAIL])
            ->assertStatus(429)
            ->assertJsonStructure(['error', 'retry_after_seconds']);
    }

    public function test_changer_dadresse_ninherite_pas_de_la_verification_precedente(): void
    {
        app(OtpService::class)->generate(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);
        app(OtpService::class)->markVerified(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);

        $autreEmail = 'autre@gmail.com';
        $this->assertFalse(app(OtpService::class)->isVerified($autreEmail, InstallationService::EMAIL_OTP_CONTEXT));
    }

    /**
     * Parcours complet à travers les deux endpoints HTTP (pas un raccourci via le service) :
     * envoi du code, vérification, puis soumission finale de /install — confirme que le statut
     * "vérifié" posé par verify-code est bien celui que install() relit.
     */
    public function test_parcours_complet_envoi_puis_verification_puis_installation(): void
    {
        Mail::fake();

        $this->postJson('/install/email/send-code', ['email' => self::EMAIL])->assertOk();
        $this->postJson('/install/email/verify-code', ['email' => self::EMAIL, 'code' => '123456'])
            ->assertOk()
            ->assertJson(['verified' => true]);

        $payload = [
            'organisation' => ['nom' => 'ELM Test', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            'admin' => [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+224622000000',
                'email' => self::EMAIL,
                'password' => 'Sup3r$ecretPwd',
            ],
        ];

        $this->post('/install', $payload)->assertOk();

        $this->assertTrue(AppInstallation::isInstalled());
    }
}
