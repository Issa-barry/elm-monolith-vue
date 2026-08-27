<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function loginAndGetToken(string $telephone, string $deviceName): string
    {
        return $this->postJson(route('api.auth.login'), [
            'telephone' => $telephone,
            'password' => 'Password@123',
            'device_name' => $deviceName,
        ])->assertOk()->json('token');
    }

    /**
     * Le guard 'sanctum' cache l'utilisateur résolu pendant toute la durée du test
     * (même mécanisme que le problème connu sous Octane) : sans ce reset, un appel
     * authentifié avec un token différent — ou avec le même token après qu'il a été
     * révoqué/expiré/désactivé — verrait encore l'état résolu par l'appel précédent.
     */
    private function asToken(string $token): static
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_multiple_devices_hold_independent_tokens(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $mobileToken = $this->loginAndGetToken('+224620000100', 'elm-mobile-android');
        $nuxtToken = $this->loginAndGetToken('+224620000100', 'elm-nuxt-web');

        $this->assertNotSame($mobileToken, $nuxtToken);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->asToken($mobileToken)->getJson(route('api.auth.me'))->assertOk();
        $this->asToken($nuxtToken)->getJson(route('api.auth.me'))->assertOk();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $mobileToken = $this->loginAndGetToken('+224620000100', 'elm-mobile-android');
        $nuxtToken = $this->loginAndGetToken('+224620000100', 'elm-nuxt-web');

        $this->asToken($nuxtToken)->postJson(route('api.auth.logout'))->assertOk();

        $this->asToken($nuxtToken)->getJson(route('api.auth.me'))->assertStatus(401);

        // Le device mobile n'est pas affecté par le logout du device Nuxt.
        $this->asToken($mobileToken)->getJson(route('api.auth.me'))->assertOk();
    }

    public function test_logout_all_revokes_every_device_token(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $mobileToken = $this->loginAndGetToken('+224620000100', 'elm-mobile-android');
        $nuxtToken = $this->loginAndGetToken('+224620000100', 'elm-nuxt-web');

        $this->asToken($nuxtToken)->postJson(route('api.auth.logout-all'))->assertOk();

        $this->asToken($mobileToken)->getJson(route('api.auth.me'))->assertStatus(401);
        $this->asToken($nuxtToken)->getJson(route('api.auth.me'))->assertStatus(401);
    }

    public function test_change_password_revokes_other_tokens_but_keeps_the_current_one(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $mobileToken = $this->loginAndGetToken('+224620000100', 'elm-mobile-android');
        $otherToken = $this->loginAndGetToken('+224620000100', 'elm-nuxt-web');

        $this->asToken($mobileToken)
            ->postJson(route('client.change-password'), [
                'current_password' => 'Password@123',
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ])
            ->assertOk();

        // Le device qui a fait le changement reste connecté...
        $this->asToken($mobileToken)->getJson(route('api.auth.me'))->assertOk();

        // ...mais tout autre device/token est déconnecté (protection en cas de token compromis).
        $this->asToken($otherToken)->getJson(route('api.auth.me'))->assertStatus(401);
    }

    public function test_password_reset_revokes_all_tokens(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $token = $this->loginAndGetToken('+224620000100', 'elm-mobile-android');

        // Simule un OTP déjà vérifié — même format de clé que OtpService::verifiedKey()
        // (prefix 'otp:verified', pas de contexte pour ce flux) : reproduire l'appel
        // complet lookup+send+verify sortirait du périmètre de ce test de non-régression
        // sur la révocation des tokens, déjà exercée par ailleurs pour le flux OTP lui-même.
        Cache::put('otp:verified:'.md5('+224620000100'), true, now()->addMinutes(10));

        $this->postJson(route('api.auth.password.reset'), [
            'telephone' => '+224620000100',
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ])->assertOk();

        $this->asToken($token)->getJson(route('api.auth.me'))->assertStatus(401);
    }

    public function test_disabling_account_after_token_issued_blocks_further_access(): void
    {
        $user = User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $token = $this->loginAndGetToken('+224620000100', 'elm-mobile-android');

        // Le token a été émis pendant que le compte était actif ; l'admin le
        // désactive ensuite — cf. audit backend du 26/08/2026.
        $user->update(['is_active' => false, 'status' => User::STATUS_INACTIVE]);

        $this->asToken($token)->getJson(route('api.auth.me'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_blocked');
    }

    public function test_token_expires_after_the_configured_duration(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $token = $this->loginAndGetToken('+224620000100', 'elm-mobile-android');

        $this->travel(91)->days();

        $this->asToken($token)->getJson(route('api.auth.me'))->assertStatus(401);
    }
}
