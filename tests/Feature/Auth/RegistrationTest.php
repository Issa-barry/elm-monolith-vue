<?php

namespace Tests\Feature\Auth;

use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Retourne un clone du test runner avec un OTP pré-vérifié en session.
     * Utilisé pour bypasser l'étape OTP dans les tests de soumission finale.
     */
    private function withVerifiedOtp(string $phone): static
    {
        return $this->withSession([
            'register_otp' => [
                $phone => ['code' => '12345', 'verified' => true, 'generated_at' => time()],
            ],
        ]);
    }

    /**
     * Crée une organisation et active explicitement le module inscription.
     * Nécessaire car INSCRIPTION est désactivé par défaut pour les nouvelles orgs.
     */
    private function createOrgWithInscription(): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::INSCRIPTION);

        return $org;
    }

    // ─── Affichage ────────────────────────────────────────────────────────────

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get(route('register'))->assertStatus(200);
    }

    public function test_registration_screen_returns_404_when_inscription_is_disabled(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->deactivate(ModuleFeature::INSCRIPTION);

        $this->get(route('register'))->assertStatus(404);
    }

    public function test_login_page_hides_register_link_when_inscription_is_disabled(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->deactivate(ModuleFeature::INSCRIPTION);

        $this->get(route('login'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('canRegister', false));
    }

    public function test_home_page_hides_register_button_when_inscription_is_disabled(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->deactivate(ModuleFeature::INSCRIPTION);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('canRegister', false));
    }

    public function test_authenticated_user_cannot_access_register(): void
    {
        $user = User::factory()->create();

        // Un utilisateur sans rôle est redirigé vers 'home'
        $this->actingAs($user)
            ->get(route('register'))
            ->assertRedirect(route('home'));
    }

    public function test_registration_submission_returns_403_when_inscription_is_disabled(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->deactivate(ModuleFeature::INSCRIPTION);

        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ])->assertStatus(403);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    // ─── Inscription réussie ───────────────────────────────────────────────────

    public function test_registration_without_phone(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ])->assertRedirect(route('client.dashboard', absolute: false));

        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'telephone' => null,
        ]);

        $user = \App\Models\User::where('email', 'test@example.com')->first();
        $this->assertTrue($user->hasRole('client'));
    }

    public function test_registration_with_valid_guinee_phone(): void
    {
        $this->withVerifiedOtp('+224620000000')
            ->post(route('register.store'), [
                'prenom' => 'Mamadou',
                'nom' => 'Diallo',
                'email' => 'mamadou@example.com',
                'telephone' => '+224620000000',
                'telephone_country' => 'GN',
                'telephone_local' => '620000000',
                'password' => 'Password@123',
            ])->assertRedirect(route('client.dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'email' => 'mamadou@example.com',
            'telephone' => '+224620000000',
        ]);
    }

    public function test_registration_with_valid_senegal_phone(): void
    {
        $this->withVerifiedOtp('+221701234567')
            ->post(route('register.store'), [
                'prenom' => 'Fatou',
                'nom' => 'Diop',
                'email' => 'fatou@example.com',
                'telephone' => '+221701234567',
                'telephone_country' => 'SN',
                'telephone_local' => '701234567',
                'password' => 'Password@123',
            ])->assertRedirect(route('client.dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'telephone' => '+221701234567',
        ]);
    }

    public function test_registration_with_valid_france_phone(): void
    {
        $this->withVerifiedOtp('+33612345678')
            ->post(route('register.store'), [
                'prenom' => 'Jean',
                'nom' => 'Dupont',
                'email' => 'jean@example.com',
                'telephone' => '+33612345678',
                'telephone_country' => 'FR',
                'telephone_local' => '612345678',
                'password' => 'Password@123',
            ])->assertRedirect(route('client.dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'telephone' => '+33612345678',
        ]);
    }

    // ─── OTP – protection côté backend ────────────────────────────────────────

    public function test_registration_with_phone_fails_without_verified_otp(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'telephone' => '+224620000001',
            'telephone_country' => 'GN',
            'telephone_local' => '620000001',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_with_unverified_otp_in_session_is_rejected(): void
    {
        // OTP généré mais pas encore vérifié
        app(OtpService::class)->generate('+224620000002');

        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'telephone' => '+224620000002',
            'telephone_country' => 'GN',
            'telephone_local' => '620000002',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone');

        $this->assertGuest();
    }

    // ─── Liaison client existant ──────────────────────────────────────────────

    public function test_registration_links_existing_client_with_same_phone(): void
    {
        $org = $this->createOrgWithInscription();
        $client = Client::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000003',
            'user_id' => null,
        ]);

        $this->withVerifiedOtp('+224620000003')
            ->post(route('register.store'), [
                'prenom' => 'Test',
                'nom' => 'User',
                'telephone' => '+224620000003',
                'telephone_country' => 'GN',
                'telephone_local' => '620000003',
                'password' => 'Password@123',
            ]);

        $user = User::where('telephone', '+224620000003')->first();
        $this->assertNotNull($user);
        $this->assertEquals($user->id, $client->fresh()->user_id);
    }

    public function test_registration_does_not_link_client_that_already_has_user(): void
    {
        $org = $this->createOrgWithInscription();
        $existingUser = User::factory()->create(['telephone' => null]);
        $client = Client::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000004',
            'user_id' => $existingUser->id,
        ]);

        $this->withVerifiedOtp('+224620000004')
            ->post(route('register.store'), [
                'prenom' => 'Test',
                'nom' => 'User',
                'telephone' => '+224620000004',
                'telephone_country' => 'GN',
                'telephone_local' => '620000004',
                'password' => 'Password@123',
            ]);

        // Le client existant ne doit pas être modifié
        $this->assertEquals($existingUser->id, $client->fresh()->user_id);
    }

    // ─── Unicité téléphone dans users ─────────────────────────────────────────

    public function test_registration_fails_when_phone_already_in_users(): void
    {
        User::factory()->create(['telephone' => '+224620000005']);

        $this->withVerifiedOtp('+224620000005')
            ->post(route('register.store'), [
                'prenom' => 'Test',
                'nom' => 'User',
                'telephone' => '+224620000005',
                'telephone_country' => 'GN',
                'telephone_local' => '620000005',
                'password' => 'Password@123',
            ])->assertSessionHasErrors('telephone');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    // ─── Validation – champs obligatoires ─────────────────────────────────────

    public function test_registration_fails_without_prenom(): void
    {
        $this->post(route('register.store'), [
            'prenom' => '',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('prenom');

        $this->assertGuest();
    }

    public function test_registration_fails_with_prenom_too_short(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'A',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('prenom');
    }

    public function test_registration_fails_with_nom_too_short(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'B',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('nom');
    }

    public function test_registration_fails_without_nom(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => '',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('nom');

        $this->assertGuest();
    }

    public function test_registration_succeeds_without_email(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'password' => 'Password@123',
        ])->assertRedirect(route('client.dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['prenom' => 'Test', 'nom' => 'USER', 'email' => null]);
    }

    public function test_registration_fails_with_invalid_email(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'not-an-email',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('email');
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'existing@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ─── Validation – mot de passe ────────────────────────────────────────────

    public function test_registration_fails_without_password(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_fails_with_password_too_short(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Abc@123',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_fails_without_uppercase(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'password@123',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_fails_without_symbol(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password123',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_succeeds_without_password_confirmation(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ])->assertRedirect(route('client.dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    // ─── Validation – téléphone ───────────────────────────────────────────────

    public function test_registration_fails_with_invalid_country_code(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'telephone_country' => 'XX',
            'telephone_local' => '123456789',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone_country');
    }

    public function test_registration_fails_with_phone_too_short_for_country(): void
    {
        // Guinée attend 9 chiffres
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '12345',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone');
    }

    public function test_registration_fails_with_phone_too_long_for_country(): void
    {
        // Guinée-Bissau attend 7 chiffres
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'telephone_country' => 'GW',
            'telephone_local' => '12345678901',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone');
    }

    public function test_registration_fails_with_non_numeric_phone(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => 'abc123def',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone_local');
    }

    // ─── Intégrité BDD ────────────────────────────────────────────────────────

    public function test_password_is_hashed_in_database(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('password', $user->password);
    }

    public function test_user_is_logged_in_after_registration(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
    }
}
