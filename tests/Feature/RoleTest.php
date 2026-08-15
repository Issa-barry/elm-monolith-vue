<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('users.read');

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function userWithoutPermission(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->get(route('roles.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('roles.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_users_read_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);

        $this->actingAs($user)
            ->get(route('roles.index'))
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->get(route('roles.edit', $role))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_without_users_read_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->get(route('roles.edit', $role))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_syncs_permissions_for_admin_entreprise(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        Permission::firstOrCreate(['name' => 'clients.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'clients.create', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), [
                'permissions' => ['clients.read', 'clients.create'],
            ])
            ->assertRedirect();

        $this->assertTrue($role->fresh()->hasPermissionTo('clients.read'));
        $this->assertTrue($role->fresh()->hasPermissionTo('clients.create'));
    }

    public function test_update_returns_403_if_not_admin_entreprise(): void
    {
        $org = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');

        $role = Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), ['permissions' => []])
            ->assertStatus(403);
    }

    public function test_update_returns_back_with_error_for_super_admin_role(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $superAdminRole), ['permissions' => []])
            ->assertRedirect();
    }

    public function test_update_as_super_admin_can_set_users_permissions(): void
    {
        $org = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.create', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        $role = Role::firstOrCreate(['name' => 'editeur', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), [
                'permissions' => ['users.read', 'users.create'],
            ])
            ->assertRedirect();

        $this->assertTrue($role->fresh()->hasPermissionTo('users.read'));
    }

    public function test_update_sets_code_and_name_for_a_custom_role(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::create(['name' => 'chef_agence', 'guard_name' => 'web', 'is_system' => false]);

        $this->actingAs($user)
            ->put(route('roles.update', $role), [
                'code' => 'CA',
                'name' => 'chef_agence_v2',
                'permissions' => [],
            ])
            ->assertRedirect();

        $role->refresh();
        $this->assertSame('CA', $role->code);
        $this->assertSame('chef_agence_v2', $role->name);
    }

    public function test_update_cannot_rename_a_system_role(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::create(['name' => 'comptable', 'guard_name' => 'web', 'is_system' => true]);

        $this->actingAs($user)
            ->put(route('roles.update', $role), [
                'name' => 'comptable_v2',
                'permissions' => [],
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame('comptable', $role->fresh()->name);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_a_role_without_any_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->post(route('roles.store'), ['name' => 'chef_agence', 'code' => 'CA'])
            ->assertRedirect();

        $role = Role::where('name', 'chef_agence')->firstOrFail();
        $this->assertSame('CA', $role->code);
        $this->assertFalse((bool) $role->is_system);
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_store_returns_403_if_not_admin_entreprise(): void
    {
        $org = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');

        $this->actingAs($user)
            ->post(route('roles.store'), ['name' => 'chef_agence'])
            ->assertStatus(403);
    }

    public function test_store_rejects_invalid_name_format(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->post(route('roles.store'), ['name' => 'Chef Agence'])
            ->assertSessionHasErrors('name');
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->post(route('roles.store'), ['name' => 'commerciale'])
            ->assertSessionHasErrors('name');
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_a_custom_role_without_users(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::create(['name' => 'chef_agence', 'guard_name' => 'web', 'is_system' => false]);

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));

        $this->assertNull(Role::find($role->id));
    }

    public function test_destroy_refuses_a_system_role(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::create(['name' => 'caissier', 'guard_name' => 'web', 'is_system' => true]);

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect();

        $this->assertNotNull(Role::find($role->id));
    }

    public function test_destroy_refuses_a_role_still_assigned_to_users(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::create(['name' => 'chef_agence', 'guard_name' => 'web', 'is_system' => false]);
        User::factory()->create(['organization_id' => $org->id])->assignRole($role);

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect();

        $this->assertNotNull(Role::find($role->id));
    }
}
