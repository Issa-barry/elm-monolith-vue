<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson(route('api.auth.login'), array_merge([
            'telephone' => '+224620000100',
            'password' => 'Password@123',
            'device_name' => 'test-device',
        ], $overrides));
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $this->login()
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'prenom', 'nom', 'telephone', 'email', 'roles']]);
    }

    public function test_login_creates_a_token_named_after_device_name(): void
    {
        $user = User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $this->login(['device_name' => 'elm-nuxt-web'])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'elm-nuxt-web',
        ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $this->login(['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_login_fails_for_unknown_phone(): void
    {
        $this->login(['telephone' => '+224699999999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_login_blocked_for_disabled_account(): void
    {
        User::factory()->create([
            'telephone' => '+224620000100',
            'password' => 'Password@123',
            'is_active' => false,
            'status' => User::STATUS_INACTIVE,
        ]);

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_blocked');
    }

    public function test_login_reports_pending_validation_distinctly_from_blocked(): void
    {
        User::factory()->create([
            'telephone' => '+224620000100',
            'password' => 'Password@123',
            'is_active' => false,
            'status' => User::STATUS_PENDING_VALIDATION,
        ]);

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'pending_validation');
    }

    public function test_login_blocked_when_email_unverified(): void
    {
        User::factory()->unverified()->create([
            'telephone' => '+224620000100',
            'email' => 'test@example.com',
            'password' => 'Password@123',
        ]);

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_login_prioritizes_account_blocked_over_email_not_verified(): void
    {
        User::factory()->unverified()->create([
            'telephone' => '+224620000100',
            'email' => 'test@example.com',
            'password' => 'Password@123',
            'is_active' => false,
            'status' => User::STATUS_INACTIVE,
        ]);

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_blocked');
    }

    public function test_login_requires_device_name(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $this->login(['device_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_name');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        for ($i = 0; $i < 10; $i++) {
            $this->login(['password' => 'wrong'])->assertStatus(422);
        }

        $this->login(['password' => 'wrong'])->assertStatus(429);
    }

    /**
     * Reproduit le cas réel constaté le 26/08/2026 : un compte staff déjà existant
     * (ex: super_admin) dont le téléphone correspond à un Proprietaire créé APRÈS
     * coup par un admin (profil "non réclamé", user_id encore null). Avant ce
     * correctif, lierCompteParTelephone() posait bien user_id mais n'attribuait
     * jamais le rôle Spatie 'proprietaire' — le compte restait donc bloqué hors de
     * l'espace client malgré un profil métier valide.
     */
    public function test_login_assigns_proprietaire_role_when_linking_an_unclaimed_profile(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000100',
            'password' => 'Password@123',
        ]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');

        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $org->id,
            'user_id' => null,
            'telephone' => '+224620000100',
        ]);

        $response = $this->login()->assertOk();

        $this->assertEqualsCanonicalizing(['super_admin', 'proprietaire'], $response->json('user.roles'));
        $this->assertTrue($proprietaire->fresh()->user_id === $user->id);
        $this->assertTrue($user->fresh()->hasRole('proprietaire'));
        $this->assertTrue($user->fresh()->hasRole('super_admin'), 'le rôle staff existant doit être conservé');
    }
}
