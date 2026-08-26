<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
}
