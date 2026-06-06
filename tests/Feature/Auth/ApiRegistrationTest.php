<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Mail\EmailVerificationMail;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApiRegistrationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Données de test ────────────────────────────────────────────────────────

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'telephone' => '+224620000100',
            'prenom' => 'Mamadou',
            'nom' => 'Diallo',
            'email' => 'mamadou@example.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
        ], $overrides);
    }

    // ─── check-phone ────────────────────────────────────────────────────────────

    public function test_check_phone_returns_not_found_for_unknown_phone(): void
    {
        $this->postJson(route('api.auth.register.check-phone'), ['telephone' => '+224699999999'])
            ->assertOk()
            ->assertJson(['status' => 'not_found', 'prefill' => null]);
    }

    public function test_check_phone_returns_user_exists_when_phone_in_users(): void
    {
        User::factory()->create(['telephone' => '+224620000200']);

        $this->postJson(route('api.auth.register.check-phone'), ['telephone' => '+224620000200'])
            ->assertOk()
            ->assertJson(['status' => 'user_exists']);
    }

    public function test_check_phone_returns_prefill_from_client(): void
    {
        $org = Organization::factory()->create();
        Client::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000201',
            'user_id' => null,
            'prenom' => 'Fatoumata',
            'nom' => 'BALDE',
        ]);

        $this->postJson(route('api.auth.register.check-phone'), ['telephone' => '+224620000201'])
            ->assertOk()
            ->assertJson([
                'status' => 'prefill_available',
                'prefill' => ['prenom' => 'Fatoumata', 'nom' => 'BALDE'],
            ]);
    }

    public function test_check_phone_returns_prefill_from_livreur(): void
    {
        $org = Organization::factory()->create();
        Livreur::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000202',
            'user_id' => null,
            'prenom' => 'Ibrahima',
            'nom' => 'CAMARA',
        ]);

        $this->postJson(route('api.auth.register.check-phone'), ['telephone' => '+224620000202'])
            ->assertOk()
            ->assertJson([
                'status' => 'prefill_available',
                'prefill' => ['prenom' => 'Ibrahima', 'nom' => 'CAMARA'],
            ]);
    }

    public function test_check_phone_returns_prefill_from_proprietaire(): void
    {
        $org = Organization::factory()->create();
        Proprietaire::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000203',
            'user_id' => null,
            'prenom' => 'Alpha',
            'nom' => 'BARRY',
        ]);

        $this->postJson(route('api.auth.register.check-phone'), ['telephone' => '+224620000203'])
            ->assertOk()
            ->assertJson([
                'status' => 'prefill_available',
                'prefill' => ['prenom' => 'Alpha', 'nom' => 'BARRY'],
            ]);
    }

    public function test_check_phone_rejects_invalid_phone(): void
    {
        $this->postJson(route('api.auth.register.check-phone'), ['telephone' => 'pas-un-numero'])
            ->assertStatus(422)
            ->assertJsonFragment(['telephone' => ['Numéro de téléphone invalide.']]);
    }

    public function test_check_phone_requires_telephone(): void
    {
        $this->postJson(route('api.auth.register.check-phone'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    // ─── Inscription réussie ────────────────────────────────────────────────────

    public function test_register_creates_user_with_pending_status(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('user.status', UserStatus::PENDING->value)
            ->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('users', [
            'telephone' => '+224620000100',
            'email' => 'mamadou@example.com',
            'status' => UserStatus::PENDING->value,
            'is_active' => false,
            'email_verified_at' => null,
        ]);
    }

    public function test_register_sends_verification_email(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        Mail::assertSent(EmailVerificationMail::class, function ($mail) {
            return $mail->hasTo('mamadou@example.com');
        });
    }

    public function test_register_stores_verification_token(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('email', 'mamadou@example.com')->first();
        $this->assertNotNull($user->email_verification_token);
        $this->assertNotNull($user->email_verification_expires_at);
    }

    public function test_register_assigns_client_role(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('email', 'mamadou@example.com')->first();
        $this->assertTrue($user->hasRole('client'));
    }

    public function test_register_formats_prenom_and_nom(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload([
            'prenom' => 'mamadou',
            'nom' => 'diallo',
        ]));

        $this->assertDatabaseHas('users', [
            'prenom' => 'Mamadou',
            'nom' => 'DIALLO',
        ]);
    }

    // ─── Validation – champs obligatoires ──────────────────────────────────────

    public function test_register_requires_telephone(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload(['telephone' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_register_requires_prenom(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload(['prenom' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('prenom');
    }

    public function test_register_requires_nom(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload(['nom' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom');
    }

    public function test_register_requires_email(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload(['email' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_requires_valid_email(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson(route('api.auth.register.store'), $this->validPayload(['email' => 'existing@example.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_rejects_duplicate_phone(): void
    {
        Mail::fake();
        User::factory()->create(['telephone' => '+224620000100']);

        $this->postJson(route('api.auth.register.store'), $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.telephone.0', fn ($v) => str_contains($v, 'existe déjà'));
    }

    // ─── Validation – mot de passe ──────────────────────────────────────────────

    public function test_register_rejects_password_without_uppercase(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload([
            'password' => 'password@123',
            'password_confirmation' => 'password@123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_register_rejects_password_without_number(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload([
            'password' => 'Password@abc',
            'password_confirmation' => 'Password@abc',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_register_rejects_password_without_symbol(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload([
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_register_rejects_mismatched_passwords(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload([
            'password' => 'Password@123',
            'password_confirmation' => 'Different@456',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // ─── Liaison personnes ──────────────────────────────────────────────────────

    public function test_register_links_existing_client(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000100',
            'user_id' => null,
        ]);

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('telephone', '+224620000100')->first();
        $this->assertEquals($user->id, $client->fresh()->user_id);
    }

    public function test_register_links_livreur_and_assigns_role(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000100',
            'user_id' => null,
        ]);

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('telephone', '+224620000100')->first();
        $this->assertEquals($user->id, $livreur->fresh()->user_id);
        $this->assertTrue($user->hasRole('livreur'));
    }

    public function test_register_links_proprietaire_and_assigns_role(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000100',
            'user_id' => null,
        ]);

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('telephone', '+224620000100')->first();
        $this->assertEquals($user->id, $proprietaire->fresh()->user_id);
        $this->assertTrue($user->hasRole('proprietaire'));
    }

    // ─── Vérification email ─────────────────────────────────────────────────────

    public function test_verify_email_activates_account(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('email', 'mamadou@example.com')->first();
        $token = $user->email_verification_token;

        $this->get(route('api.auth.verify-email', ['token' => $token]))
            ->assertOk()
            ->assertSee('validé avec succès');

        $user->refresh();
        $this->assertEquals(UserStatus::ACTIVE->value, $user->status);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);
    }

    public function test_verify_email_returns_404_for_invalid_token(): void
    {
        $this->get(route('api.auth.verify-email', ['token' => 'invalid-token-xxx']))
            ->assertNotFound();
    }

    public function test_verify_email_returns_410_for_expired_token(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('email', 'mamadou@example.com')->first();
        $user->update(['email_verification_expires_at' => now()->subHour()]);

        $this->get(route('api.auth.verify-email', ['token' => $user->email_verification_token]))
            ->assertStatus(410);
    }

    // ─── Connexion bloquée avant vérification email ─────────────────────────────

    public function test_login_blocked_when_email_not_verified(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $this->postJson(route('api.auth.login'), [
            'telephone' => '+224620000100',
            'password' => 'Password@123',
            'device_name' => 'test',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_login_allowed_after_email_verification(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.register.store'), $this->validPayload());

        $user = User::where('email', 'mamadou@example.com')->first();
        $token = $user->email_verification_token;

        $this->get(route('api.auth.verify-email', ['token' => $token]));

        $this->postJson(route('api.auth.login'), [
            'telephone' => '+224620000100',
            'password' => 'Password@123',
            'device_name' => 'test',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }
}
