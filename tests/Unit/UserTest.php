<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    // ── Attribut name ─────────────────────────────────────────────────────────

    public function test_name_combines_prenom_and_nom(): void
    {
        $user = User::factory()->make(['prenom' => 'Mamadou', 'nom' => 'BARRY']);

        $this->assertSame('Mamadou BARRY', $user->name);
    }

    public function test_name_trims_whitespace_when_prenom_empty(): void
    {
        $user = User::factory()->make(['prenom' => '', 'nom' => 'DIALLO']);

        $this->assertSame('DIALLO', $user->name);
    }

    public function test_name_trims_whitespace_when_nom_empty(): void
    {
        $user = User::factory()->make(['prenom' => 'Alpha', 'nom' => '']);

        $this->assertSame('Alpha', $user->name);
    }

    // ── isSuperAdmin ──────────────────────────────────────────────────────────

    public function test_is_super_admin_returns_true_for_super_admin_role(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        $this->assertTrue($user->isSuperAdmin());
    }

    public function test_is_super_admin_returns_false_for_other_roles(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');

        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_is_super_admin_returns_false_for_user_without_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse($user->isSuperAdmin());
    }

    // ── permissionsMap ────────────────────────────────────────────────────────

    public function test_permissions_map_returns_59_keys(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $map = $user->permissionsMap();

        // 14 resources × 4 actions + 2 standalone (logistique.commission.verser, ventes.qte.update)
        $this->assertCount(59, $map);
    }

    public function test_permissions_map_keys_follow_resource_dot_action_format(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $map = $user->permissionsMap();

        $this->assertArrayHasKey('users.read', $map);
        $this->assertArrayHasKey('users.create', $map);
        $this->assertArrayHasKey('users.update', $map);
        $this->assertArrayHasKey('users.delete', $map);
        $this->assertArrayHasKey('clients.read', $map);
        $this->assertArrayHasKey('ventes.create', $map);
        $this->assertArrayHasKey('ventes.prix.update', $map);
    }

    public function test_permissions_map_all_true_for_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        $map = $user->permissionsMap();

        foreach ($map as $key => $value) {
            $this->assertTrue($value, "Expected {$key} to be true for super_admin");
        }
    }

    public function test_permissions_map_respects_individual_permissions(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'clients.read', 'guard_name' => 'web']);

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['users.read', 'clients.read']);

        $map = $user->permissionsMap();

        $this->assertTrue($map['users.read']);
        $this->assertTrue($map['clients.read']);
        $this->assertFalse($map['users.create']);
        $this->assertFalse($map['users.delete']);
    }

    public function test_permissions_map_all_false_for_user_without_permissions(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');

        $map = $user->permissionsMap();

        foreach ($map as $key => $value) {
            $this->assertFalse($value, "Expected {$key} to be false for user without permissions");
        }
    }

    // ── Relation organization ─────────────────────────────────────────────────

    public function test_organization_relation_returns_correct_organization(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertInstanceOf(Organization::class, $user->organization);
        $this->assertEquals($org->id, $user->organization->id);
    }

    public function test_user_belongs_to_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $user1 = User::factory()->create(['organization_id' => $org1->id]);
        $user2 = User::factory()->create(['organization_id' => $org2->id]);

        $this->assertNotEquals($user1->organization->id, $user2->organization->id);
    }

    // ── Relation sites ────────────────────────────────────────────────────────

    public function test_sites_relation_returns_attached_sites(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Dépôt Central',
            'type' => 'depot',
        ]);

        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->assertCount(1, $user->sites);
        $this->assertEquals($site->id, $user->sites->first()->id);
    }

    public function test_sites_pivot_includes_is_default_and_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Agence Nord',
            'type' => 'agence',
        ]);

        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $pivot = $user->sites()->withPivot('role', 'is_default')->first()->pivot;
        $this->assertEquals('employe', $pivot->role);
        $this->assertTrue((bool) $pivot->is_default);
    }

    public function test_default_site_query_returns_only_default(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $site1 = Site::create(['organization_id' => $org->id, 'nom' => 'Site A', 'type' => 'depot']);
        $site2 = Site::create(['organization_id' => $org->id, 'nom' => 'Site B', 'type' => 'agence']);

        $user->sites()->attach($site1->id, ['role' => 'employe', 'is_default' => true]);
        $user->sites()->attach($site2->id, ['role' => 'employe', 'is_default' => false]);

        $default = $user->sites()->wherePivot('is_default', true)->first();

        $this->assertNotNull($default);
        $this->assertEquals($site1->id, $default->id);
    }

    // ── Casts ─────────────────────────────────────────────────────────────────

    public function test_is_active_is_cast_to_boolean(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertIsBool($user->is_active);
        $this->assertTrue($user->is_active);
    }

    public function test_is_active_defaults_behavior_on_factory(): void
    {
        $org = Organization::factory()->create();
        $active = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $inactive = User::factory()->create(['organization_id' => $org->id, 'is_active' => false]);

        $this->assertTrue($active->is_active);
        $this->assertFalse($inactive->is_active);
    }
}
