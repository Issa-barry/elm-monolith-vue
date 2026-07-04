<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Couvre le workflow de validation des comptes créés via invitation :
 * un compte reste pending_validation (sans accès) tant qu'un admin ne l'a pas
 * validé, et un rôle admin ne peut jamais être attribué à un compte non validé.
 */
class AccountValidationTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function createSite(Organization $org): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => 'Dépôt Central',
            'type' => 'depot',
        ]);
    }

    private function superAdmin(Organization $org): User
    {
        $this->createRole('super_admin');

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function pendingUser(Organization $org, array $overrides = []): User
    {
        $this->createRole('manager');

        $user = User::factory()->withoutTwoFactor()->create(array_merge([
            'organization_id' => $org->id,
            'is_active' => false,
            'status' => User::STATUS_PENDING_VALIDATION,
        ], $overrides));
        $user->assignRole('manager');

        $site = $this->createSite($org);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── validate ──────────────────────────────────────────────────────────────

    public function test_admin_can_validate_pending_account(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $target = $this->pendingUser($org);

        $this->actingAs($admin)
            ->patch(route('users.validate', $target))
            ->assertStatus(302)
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertTrue($target->is_active);
        $this->assertSame(User::STATUS_ACTIVE, $target->status);
    }

    public function test_validate_redirects_back_to_originating_page(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $site = $this->createSite($org);
        $target = $this->pendingUser($org);

        $this->actingAs($admin)
            ->from(route('sites.show', $site).'?tab=membres')
            ->patch(route('users.validate', $target))
            ->assertRedirect(route('sites.show', $site).'?tab=membres');
    }

    public function test_validated_user_can_login(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $target = $this->pendingUser($org);

        $this->actingAs($admin)->patch(route('users.validate', $target));
        auth()->logout();

        $this->post(route('login.store'), [
            'telephone' => $target->telephone,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($target);
    }

    public function test_cannot_validate_an_already_active_account(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $target = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($admin)
            ->patch(route('users.validate', $target))
            ->assertStatus(422);
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function test_admin_can_reject_pending_account(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $target = $this->pendingUser($org);

        $this->actingAs($admin)
            ->patch(route('users.reject', $target))
            ->assertStatus(302)
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertSame(User::STATUS_INACTIVE, $target->status);
    }

    public function test_rejected_user_still_cannot_login(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $target = $this->pendingUser($org);

        $this->actingAs($admin)->patch(route('users.reject', $target));
        auth()->logout();

        $this->post(route('login.store'), [
            'telephone' => $target->telephone,
            'password' => 'password',
        ])->assertSessionHasErrors('telephone');

        $this->assertGuest();
    }

    // ── rôle admin uniquement après validation ───────────────────────────────

    public function test_admin_role_cannot_be_assigned_while_account_is_pending(): void
    {
        $this->createRole('admin_entreprise');

        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $site = $this->createSite($org);
        $target = $this->pendingUser($org);

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'prenom' => $target->prenom,
                'nom' => $target->nom,
                'email' => null,
                'telephone' => $target->telephone,
                'role' => 'admin_entreprise',
                'site_id' => $site->id,
                'password' => '',
                'password_confirmation' => '',
            ])->assertSessionHasErrors('role');

        $this->assertFalse($target->fresh()->hasRole('admin_entreprise'));
    }

    public function test_admin_role_can_be_assigned_after_validation(): void
    {
        $this->createRole('admin_entreprise');

        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $site = $this->createSite($org);
        $target = $this->pendingUser($org);

        $this->actingAs($admin)->patch(route('users.validate', $target));

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'prenom' => $target->prenom,
                'nom' => $target->nom,
                'email' => null,
                'telephone' => $target->telephone,
                'role' => 'admin_entreprise',
                'site_id' => $site->id,
                'password' => '',
                'password_confirmation' => '',
            ])->assertRedirect(route('users.edit', $target));

        $this->assertTrue($target->fresh()->hasRole('admin_entreprise'));
    }
}
