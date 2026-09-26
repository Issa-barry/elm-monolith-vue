<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountControllerTest extends TestCase
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
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        $site = $this->createSite($org);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function adminUser(Organization $org): User
    {
        $this->createRole('admin_entreprise');
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('users.read');

        $site = $this->createSite($org);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $response = $this->actingAs($admin)->get(route('comptes.index'));

        $response->assertStatus(200);
        $props = $response->original->getData()['page']['props'];
        $this->assertArrayHasKey('accounts', $props);
        $this->assertArrayHasKey('role_labels', $props);
    }

    public function test_index_returns_200_for_org_scoped_user_with_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->adminUser($org);

        $this->actingAs($user)
            ->get(route('comptes.index'))
            ->assertStatus(200);
    }

    public function test_index_returns_403_without_permission(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $site = $this->createSite($org);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)
            ->get(route('comptes.index'))
            ->assertStatus(403);
    }

    public function test_index_returns_403_when_module_disabled_for_non_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->adminUser($org);
        Feature::for($org)->deactivate(ModuleFeature::UTILISATEURS);

        $this->actingAs($user)
            ->get(route('comptes.index'))
            ->assertStatus(403);
    }

    public function test_index_redirects_unauthenticated(): void
    {
        $this->get(route('comptes.index'))->assertRedirect(route('login'));
    }

    public function test_index_super_admin_sees_accounts_across_organizations(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $admin = $this->superAdmin($org1);
        $otherOrgUser = User::factory()->create(['organization_id' => $org2->id]);

        $response = $this->actingAs($admin)->get(route('comptes.index'));

        $response->assertStatus(200);
        $accounts = $response->original->getData()['page']['props']['accounts'];
        $ids = array_column($accounts, 'id');
        $this->assertContains($otherOrgUser->id, $ids);
    }

    // ── toggleActive ─────────────────────────────────────────────────────────

    public function test_toggle_active_toggles_status_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('comptes.toggle-active', $target))
            ->assertRedirect();

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_toggle_active_returns_403_for_non_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->adminUser($org);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('comptes.toggle-active', $target))
            ->assertStatus(403);

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_toggle_active_returns_403_when_toggling_own_account(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->patch(route('comptes.toggle-active', $admin))
            ->assertStatus(403);

        $this->assertTrue($admin->fresh()->is_active);
    }
}
