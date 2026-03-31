<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new UserPolicy;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function userWithRole(Organization $org, string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        return $user;
    }

    private function userWithPermissions(Organization $org, array $permissions): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);
        return $user;
    }

    // ── viewAny ───────────────────────────────────────────────────────────────

    public function test_view_any_allowed_with_users_read_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org, ['users.read']);

        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_view_any_denied_without_users_read_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRole($org, 'manager');

        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_view_any_allowed_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRole($org, 'super_admin');
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        $user->givePermissionTo('users.read');

        $this->assertTrue($this->policy->viewAny($user));
    }

    // ── view ──────────────────────────────────────────────────────────────────

    public function test_view_allowed_same_org_with_permission(): void
    {
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithPermissions($org, ['users.read']);
        $target = User::factory()->create(['organization_id' => $org->id]);

        $this->assertTrue($this->policy->view($actor, $target));
    }

    public function test_view_denied_different_org(): void
    {
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $actor = $this->userWithPermissions($org1, ['users.read']);
        $target = User::factory()->create(['organization_id' => $org2->id]);

        $this->assertFalse($this->policy->view($actor, $target));
    }

    public function test_view_denied_without_permission(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'manager');
        $target = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse($this->policy->view($actor, $target));
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_allowed_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'super_admin');

        $this->assertTrue($this->policy->create($actor));
    }

    public function test_create_denied_for_admin_entreprise(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'admin_entreprise');

        $this->assertFalse($this->policy->create($actor));
    }

    public function test_create_denied_for_manager(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'manager');

        $this->assertFalse($this->policy->create($actor));
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_allowed_with_permission_same_org(): void
    {
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithPermissions($org, ['users.update']);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->assertTrue($this->policy->update($actor, $target));
    }

    public function test_update_denied_without_permission(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'manager');
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->assertFalse($this->policy->update($actor, $target));
    }

    public function test_update_denied_across_organizations(): void
    {
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $actor = $this->userWithPermissions($org1, ['users.update']);
        $target = User::factory()->create(['organization_id' => $org2->id]);

        $this->assertFalse($this->policy->update($actor, $target));
    }

    public function test_update_denied_when_targeting_super_admin_as_non_super_admin(): void
    {
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithPermissions($org, ['users.update']);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('super_admin');

        $this->assertFalse($this->policy->update($actor, $target));
    }

    public function test_update_allowed_when_super_admin_targets_another_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'super_admin');
        $actor->givePermissionTo('users.update');
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('super_admin');

        $this->assertTrue($this->policy->update($actor, $target));
    }

    public function test_update_allowed_for_super_admin_targeting_regular_user(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'super_admin');
        $actor->givePermissionTo('users.update');
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->assertTrue($this->policy->update($actor, $target));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function test_delete_allowed_for_super_admin_targeting_another_user(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'super_admin');
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->assertTrue($this->policy->delete($actor, $target));
    }

    public function test_delete_denied_for_non_super_admin(): void
    {
        Permission::firstOrCreate(['name' => 'users.delete', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithPermissions($org, ['users.delete']);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->assertFalse($this->policy->delete($actor, $target));
    }

    public function test_delete_denied_when_targeting_self(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'super_admin');

        $this->assertFalse($this->policy->delete($actor, $actor));
    }

    public function test_delete_denied_across_organizations(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $actor = $this->userWithRole($org1, 'super_admin');
        $target = User::factory()->create(['organization_id' => $org2->id]);
        $target->assignRole('manager');

        $this->assertFalse($this->policy->delete($actor, $target));
    }

    public function test_delete_denied_when_targeting_super_admin_as_non_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $actor = $this->userWithRole($org, 'admin_entreprise');
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('super_admin');

        $this->assertFalse($this->policy->delete($actor, $target));
    }
}
