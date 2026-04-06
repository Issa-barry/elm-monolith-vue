<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_login_normalizes_phone_with_spaces(): void
    {
        $user = User::factory()->withoutTwoFactor()->create([
            'telephone' => '+33758855039',
        ]);

        // Le front peut envoyer des espaces si l'utilisateur copie-colle
        $this->post(route('login.store'), [
            'telephone' => '+33 758 855 039',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_login_normalizes_phone_with_dashes(): void
    {
        $user = User::factory()->withoutTwoFactor()->create([
            'telephone' => '+224622176056',
        ]);

        $this->post(route('login.store'), [
            'telephone' => '+224-622-176-056',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_login_rejects_empty_telephone(): void
    {
        $this->post(route('login.store'), [
            'telephone' => '',
            'password' => 'password',
        ])->assertSessionHasErrors('telephone');

        $this->assertGuest();
    }

    public function test_login_rejects_local_format_without_plus(): void
    {
        User::factory()->create(['telephone' => '+33758855039']);

        // Format local sans "+" → rejeté avec message de format
        $this->post(route('login.store'), [
            'telephone' => '0758855039',
            'password' => 'password',
        ])->assertSessionHasErrors('telephone');

        $this->assertGuest();
    }

    public function test_login_phone_format_error_message_is_in_french(): void
    {
        $response = $this->post(route('login.store'), [
            'telephone' => 'invalid',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('telephone');
        $this->assertStringContainsString(
            'Format de téléphone invalide',
            session('errors')->first('telephone'),
        );
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge(): void
    {
        if (! Features::canManageTwoFactorAuthentication()) {
            $this->markTestSkipped('Two-factor authentication is not enabled.');
        }

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_failed_login_error_message_is_in_french(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('telephone');
        $this->assertStringContainsString(
            'incorrect',
            session('errors')->first('telephone'),
        );
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), [
                'telephone' => $user->telephone,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }

    public function test_rate_limit_error_message_is_in_french(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), [
                'telephone' => $user->telephone,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
        $response->assertSessionHasErrors('telephone');
        $this->assertStringContainsString(
            'Trop de tentatives',
            session('errors')->first('telephone'),
        );
    }
}
