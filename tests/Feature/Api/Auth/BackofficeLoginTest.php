<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Couverture minimale ciblée sur le correctif de synchronisation des rôles
 * (26/08/2026) — cf. LoginTest::test_login_assigns_proprietaire_role_when_linking_an_unclaimed_profile,
 * dont ce fichier reproduit le même scénario pour BackofficeLoginController
 * (même bug, même correctif, code dupliqué entre les deux contrôleurs).
 */
class BackofficeLoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $overrides = [])
    {
        return $this->postJson(route('api.backoffice.auth.login'), array_merge([
            'telephone' => '+224620000100',
            'password' => 'Password@123',
            'device_name' => 'elm-pro-android',
        ], $overrides));
    }

    public function test_login_succeeds_for_a_staff_account(): void
    {
        $user = User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');

        $this->login()->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_login_rejects_a_non_staff_account(): void
    {
        $user = User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);
        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
        $user->assignRole('proprietaire');

        $this->login()->assertStatus(403)->assertJsonPath('code', 'not_staff');
    }

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

        $this->login()->assertOk();

        $this->assertTrue($proprietaire->fresh()->user_id === $user->id);
        $this->assertTrue($user->fresh()->hasRole('proprietaire'));
        $this->assertTrue($user->fresh()->hasRole('super_admin'));
    }
}
