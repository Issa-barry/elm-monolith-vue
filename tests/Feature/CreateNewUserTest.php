<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class CreateNewUserTest extends TestCase
{
    use RefreshDatabase;

    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'prenom' => 'Mamadou',
            'nom' => 'Diallo',
            'email' => 'mamadou@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '622000001',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    /**
     * Pré-vérifie l'OTP en session pour le numéro donné,
     * afin de bypasser la vérification dans les tests qui n'en testent pas le mécanisme.
     */
    private function verifyOtp(string $phone): void
    {
        /** @var OtpService $otp */
        $otp = app(OtpService::class);
        $otp->generate($phone);
        $otp->markVerified($phone);
    }

    // ─── Rôle et formatage ────────────────────────────────────────────────────

    public function test_create_user_assigns_client_role(): void
    {
        $this->verifyOtp('+224622000001');

        $action = new CreateNewUser;
        $user = $action->create($this->validInput());

        $this->assertTrue($user->hasRole('client'));
    }

    public function test_create_user_formats_prenom_with_title_case(): void
    {
        $this->verifyOtp('+224622000001');

        $action = new CreateNewUser;
        $user = $action->create($this->validInput(['prenom' => 'jean-paul']));

        $this->assertSame('Jean-Paul', $user->prenom);
    }

    public function test_create_user_uppercases_nom(): void
    {
        $this->verifyOtp('+224622000001');

        $action = new CreateNewUser;
        $user = $action->create($this->validInput(['nom' => 'diallo']));

        $this->assertSame('DIALLO', $user->nom);
    }

    // ─── Téléphone ────────────────────────────────────────────────────────────

    public function test_create_user_builds_e164_telephone(): void
    {
        $this->verifyOtp('+224622000002');

        $action = new CreateNewUser;
        $user = $action->create($this->validInput([
            'email' => 'mamadou2@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '622000002',
        ]));

        $this->assertSame('+224622000002', $user->telephone);
    }

    public function test_create_user_without_telephone(): void
    {
        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test.user@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertNull($user->telephone);
    }

    public function test_create_user_fails_with_invalid_country(): void
    {
        $this->expectException(ValidationException::class);

        $action = new CreateNewUser;
        $action->create($this->validInput([
            'telephone_country' => 'XX',
            'telephone_local' => '123456789',
        ]));
    }

    public function test_create_user_fails_with_wrong_local_length(): void
    {
        $this->expectException(ValidationException::class);

        $action = new CreateNewUser;
        $action->create($this->validInput([
            'telephone_country' => 'GN',
            'telephone_local' => '12345', // too short for GN (needs 9)
        ]));
    }

    public function test_create_user_accepts_legacy_telephone_e164(): void
    {
        $this->verifyOtp('+224622000003');

        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Legacy',
            'nom' => 'User',
            'email' => 'legacy@example.com',
            'telephone' => '+224622000003',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertSame('+224622000003', $user->telephone);
    }

    public function test_create_user_accepts_legacy_telephone_with_00_prefix(): void
    {
        $this->verifyOtp('+224622000004');

        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Legacy',
            'nom' => 'User2',
            'email' => 'legacy2@example.com',
            'telephone' => '00224622000004',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertSame('+224622000004', $user->telephone);
    }

    public function test_create_user_fails_with_invalid_legacy_telephone(): void
    {
        $this->expectException(ValidationException::class);

        $action = new CreateNewUser;
        $action->create([
            'prenom' => 'Bad',
            'nom' => 'Phone',
            'email' => 'badphone@example.com',
            'telephone' => 'not-a-phone',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
    }

    public function test_create_user_lowercases_email(): void
    {
        $this->verifyOtp('+224622000001');

        $action = new CreateNewUser;
        $user = $action->create($this->validInput([
            'email' => 'USER@EXAMPLE.COM',
        ]));

        $this->assertSame('user@example.com', $user->email);
    }

    // ─── Vérification OTP ────────────────────────────────────────────────────

    public function test_create_user_fails_without_verified_otp_when_phone_provided(): void
    {
        $this->expectException(ValidationException::class);

        // OTP non vérifié
        $action = new CreateNewUser;
        $action->create($this->validInput()); // téléphone GN présent mais pas d'OTP
    }

    public function test_create_user_fails_with_unverified_otp(): void
    {
        $this->expectException(ValidationException::class);

        // OTP généré mais pas encore vérifié (verified = false)
        app(OtpService::class)->generate('+224622000001');

        $action = new CreateNewUser;
        $action->create($this->validInput());
    }

    // ─── Liaison client existant ──────────────────────────────────────────────

    public function test_create_user_links_existing_client_with_null_user_id(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::INSCRIPTION);
        $client = Client::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224622000010',
            'user_id' => null,
        ]);

        $this->verifyOtp('+224622000010');

        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'link@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '622000010',
            'password' => 'Password123!',
        ]);

        $this->assertEquals($user->id, $client->fresh()->user_id);
    }

    public function test_create_user_creates_client_from_livreur(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::INSCRIPTION);
        Livreur::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224622000011',
            'prenom' => 'Alpha',
            'nom' => 'DIALLO',
        ]);

        $this->verifyOtp('+224622000011');

        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Alpha',
            'nom' => 'Diallo',
            'email' => 'livreur@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '622000011',
            'password' => 'Password123!',
        ]);

        $this->assertDatabaseHas('clients', [
            'organization_id' => $org->id,
            'telephone' => '+224622000011',
            'user_id' => $user->id,
        ]);
    }

    public function test_create_user_links_proprietaire_and_creates_client(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::INSCRIPTION);
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224622000012',
            'user_id' => null,
        ]);

        $this->verifyOtp('+224622000012');

        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Test',
            'nom' => 'Prop',
            'email' => 'prop@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '622000012',
            'password' => 'Password123!',
        ]);

        // Le propriétaire doit être lié
        $this->assertEquals($user->id, $proprietaire->fresh()->user_id);

        // Un client doit être créé dans la même org
        $this->assertDatabaseHas('clients', [
            'organization_id' => $org->id,
            'telephone' => '+224622000012',
            'user_id' => $user->id,
        ]);
    }

    // ─── OTP nettoyé après création ───────────────────────────────────────────

    public function test_otp_is_cleared_from_session_after_successful_registration(): void
    {
        $this->verifyOtp('+224622000013');

        $action = new CreateNewUser;
        $action->create([
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'otpcleared@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '622000013',
            'password' => 'Password123!',
        ]);

        $this->assertFalse(
            app(OtpService::class)->isVerified('+224622000013'),
            'OTP should be cleared from session after successful user creation.',
        );
    }
}
