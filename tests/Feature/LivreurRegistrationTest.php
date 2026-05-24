<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivreurRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function verifyOtp(string $phone): void
    {
        $otp = app(OtpService::class);
        $otp->generate($phone);
        $otp->markVerified($phone);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'prenom' => 'Alpha',
            'nom' => 'Diallo',
            'telephone' => '+224622111001',
            'telephone_country' => 'GN',
            'telephone_local' => '622111001',
            'password' => 'Password123!',
        ], $overrides);
    }

    // ── Inscription réussie ───────────────────────────────────────────────────

    public function test_livreur_can_register_with_valid_otp(): void
    {
        Organization::factory()->create();
        $this->verifyOtp('+224622111001');

        $this->post('/register/livreur', $this->validPayload())
            ->assertRedirect(route('client.pending'));

        $user = User::where('telephone', '+224622111001')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('livreur'));

        $this->assertDatabaseHas('livreurs', [
            'telephone' => '+224622111001',
            'is_active' => false,
        ]);
    }

    public function test_new_livreur_record_is_created_when_no_preexisting_record(): void
    {
        Organization::factory()->create();
        $this->verifyOtp('+224622111001');

        $this->post('/register/livreur', $this->validPayload());

        $livreur = Livreur::where('telephone', '+224622111001')->first();
        $this->assertNotNull($livreur);
        $this->assertFalse($livreur->is_active);

        $user = User::where('telephone', '+224622111001')->first();
        $this->assertSame($user->id, $livreur->user_id);
    }

    // ── Liaison à un livreur pré-créé par admin ───────────────────────────────

    public function test_registration_links_user_to_existing_admin_created_livreur(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224622111001',
            'user_id' => null,
        ]);

        $this->verifyOtp('+224622111001');

        $this->post('/register/livreur', $this->validPayload())
            ->assertRedirect(route('client.pending'));

        $user = User::where('telephone', '+224622111001')->first();
        $this->assertSame($user->id, $livreur->fresh()->user_id);
        $this->assertDatabaseCount('livreurs', 1);
    }

    // ── Échecs de validation ──────────────────────────────────────────────────

    public function test_registration_fails_without_otp_verification(): void
    {
        Organization::factory()->create();

        $this->post('/register/livreur', $this->validPayload())
            ->assertSessionHasErrors(['telephone']);
    }

    public function test_registration_fails_with_unverified_otp(): void
    {
        Organization::factory()->create();
        app(OtpService::class)->generate('+224622111001'); // généré mais pas vérifié

        $this->post('/register/livreur', $this->validPayload())
            ->assertSessionHasErrors(['telephone']);
    }

    public function test_registration_fails_if_phone_already_has_account(): void
    {
        Organization::factory()->create();
        User::factory()->create(['telephone' => '+224622111001']);
        $this->verifyOtp('+224622111001');

        $this->post('/register/livreur', $this->validPayload())
            ->assertSessionHasErrors(['telephone']);
    }

    public function test_registration_fails_with_missing_required_fields(): void
    {
        $this->post('/register/livreur', [])
            ->assertSessionHasErrors(['prenom', 'nom', 'telephone', 'telephone_country', 'telephone_local', 'password']);
    }

    // ── Nettoyage OTP ─────────────────────────────────────────────────────────

    public function test_otp_is_cleared_after_successful_registration(): void
    {
        Organization::factory()->create();
        $this->verifyOtp('+224622111001');

        $this->post('/register/livreur', $this->validPayload());

        $this->assertFalse(app(OtpService::class)->isVerified('+224622111001'));
    }
}
