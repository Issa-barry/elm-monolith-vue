<?php

namespace Tests\Feature\Api\Client;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class UpdateNotificationPreferencesControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    public function test_defaults_to_enabled_before_any_preference_is_set(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile.notifications.activite', true);
    }

    public function test_can_disable_a_preference(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.notification-preferences'), [
            'preferences' => ['activite' => false],
        ])
            ->assertOk()
            ->assertJsonPath('notifications.activite', false);

        $this->assertFalse($user->fresh()->notificationPreferences()['activite']);
    }

    public function test_preference_persists_across_requests(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.notification-preferences'), [
            'preferences' => ['activite' => false],
        ])->assertOk();

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile.notifications.activite', false);
    }

    public function test_unknown_preference_keys_are_silently_ignored(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.notification-preferences'), [
            'preferences' => ['activite' => false, 'inconnue' => true],
        ])->assertOk();

        $this->assertSame(['activite' => false], $user->fresh()->notification_preferences);
    }

    public function test_a_user_can_never_modify_another_users_preference(): void
    {
        $org = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($org);
        $userB = $this->makeProprietaireUser($org);

        Sanctum::actingAs($userA, ['*']);
        $this->patchJson(route('client.profile.notification-preferences'), [
            'preferences' => ['activite' => false],
        ])->assertOk();

        $this->assertTrue($userB->fresh()->notificationPreferences()['activite']);
    }

    public function test_requires_a_client_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.notification-preferences'), [
            'preferences' => ['activite' => false],
        ])->assertStatus(403);
    }
}
