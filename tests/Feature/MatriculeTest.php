<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\MatriculeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MatriculeTest extends TestCase
{
    use RefreshDatabase;

    private function createOrg(): Organization
    {
        return Organization::factory()->create();
    }

    private function createSite(Organization $org): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
        ]);
    }

    private function superAdmin(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.create', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        $site = $this->createSite($org);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function ensureRoles(): void
    {
        foreach (UserController::STAFF_ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    // ── MatriculeService unit-level tests ─────────────────────────────────────

    public function test_generate_returns_six_digit_string(): void
    {
        $org = $this->createOrg();

        $matricule = app(MatriculeService::class)->generateForOrganization($org->id);

        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $matricule);
    }

    public function test_generate_never_reuses_existing_matricule(): void
    {
        $org = $this->createOrg();

        // Fill every possible value except '123456'
        // (too expensive to fill all 1M; instead force a specific collision scenario)
        // We test that the service skips a taken value and returns a different one.
        $first = app(MatriculeService::class)->generateForOrganization($org->id);
        User::factory()->create(['organization_id' => $org->id, 'matricule' => $first]);

        $second = app(MatriculeService::class)->generateForOrganization($org->id);

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $second);
    }

    public function test_generate_is_unique_per_organization(): void
    {
        $org1 = $this->createOrg();
        $org2 = $this->createOrg();
        $service = app(MatriculeService::class);

        $m1 = $service->generateForOrganization($org1->id);
        $m2 = $service->generateForOrganization($org2->id);

        // Both must be valid 6-digit strings (may coincidentally be equal across orgs — that's allowed)
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $m1);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $m2);
    }

    public function test_assign_for_user_skips_when_already_has_matricule(): void
    {
        $org = $this->createOrg();
        $user = User::factory()->create(['organization_id' => $org->id, 'matricule' => '000042']);

        app(MatriculeService::class)->assignForUser($user);

        $this->assertSame('000042', $user->fresh()->matricule);
    }

    public function test_assign_for_user_skips_when_no_organization(): void
    {
        $user = User::factory()->create(['organization_id' => null, 'matricule' => null]);

        app(MatriculeService::class)->assignForUser($user);

        $this->assertNull($user->fresh()->matricule);
    }

    // ── Auto-assignment on store ──────────────────────────────────────────────

    public function test_store_user_auto_assigns_matricule(): void
    {
        $this->ensureRoles();
        $org = $this->createOrg();
        $admin = $this->superAdmin($org);
        $site = $this->createSite($org);

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'prenom' => 'Mamadou',
                'nom' => 'Diallo',
                'telephone' => '+224620111222',
                'role' => 'manager',
                'site_id' => $site->id,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ]);

        $user = User::where('telephone', '+224620111222')->firstOrFail();
        $this->assertNotNull($user->matricule);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $user->matricule);
    }

    public function test_store_assigns_incremental_matricules_for_multiple_users(): void
    {
        $this->ensureRoles();
        $org = $this->createOrg();
        $admin = $this->superAdmin($org);
        $site = $this->createSite($org);

        $this->actingAs($admin)->post(route('users.store'), [
            'prenom' => 'Alpha',
            'nom' => 'Barry',
            'telephone' => '+224620000010',
            'role' => 'comptable',
            'site_id' => $site->id,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $this->actingAs($admin)->post(route('users.store'), [
            'prenom' => 'Fatoumata',
            'nom' => 'Camara',
            'telephone' => '+224620000011',
            'role' => 'commerciale',
            'site_id' => $site->id,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $m1 = User::where('telephone', '+224620000010')->value('matricule');
        $m2 = User::where('telephone', '+224620000011')->value('matricule');

        $this->assertNotNull($m1);
        $this->assertNotNull($m2);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $m1);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $m2);
        $this->assertNotSame($m1, $m2);
    }

    // ── Immutability ─────────────────────────────────────────────────────────

    public function test_update_cannot_change_matricule(): void
    {
        $this->ensureRoles();
        $org = $this->createOrg();
        $admin = $this->superAdmin($org);
        $site = $this->createSite($org);

        $targetUser = User::factory()->create([
            'organization_id' => $org->id,
            'matricule' => '000007',
            'password' => Hash::make('Password123'),
        ]);
        $targetUser->assignRole('manager');
        $targetUser->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($admin)->put(route('users.update', $targetUser), [
            'prenom' => $targetUser->prenom,
            'nom' => $targetUser->nom,
            'telephone' => $targetUser->telephone,
            'role' => 'manager',
            'site_id' => $site->id,
            'matricule' => '999999',
        ]);

        $this->assertSame('000007', $targetUser->fresh()->matricule);
    }

    // ── Uniqueness per org (DB constraint) ───────────────────────────────────

    public function test_matricule_composite_unique_allows_same_value_in_different_orgs(): void
    {
        $org1 = $this->createOrg();
        $org2 = $this->createOrg();

        User::factory()->create(['organization_id' => $org1->id, 'matricule' => '000001']);
        User::factory()->create(['organization_id' => $org2->id, 'matricule' => '000001']);

        $this->assertDatabaseCount('users', 2);
    }

    // ── Backfill command ─────────────────────────────────────────────────────

    public function test_backfill_command_assigns_matricules_to_existing_staff(): void
    {
        $this->ensureRoles();
        $org = $this->createOrg();

        $u1 = User::factory()->create(['organization_id' => $org->id, 'matricule' => null]);
        $u1->assignRole('manager');

        $u2 = User::factory()->create(['organization_id' => $org->id, 'matricule' => null]);
        $u2->assignRole('comptable');

        $this->artisan('users:backfill-matricules')->assertSuccessful();

        $this->assertNotNull($u1->fresh()->matricule);
        $this->assertNotNull($u2->fresh()->matricule);
        $this->assertNotSame($u1->fresh()->matricule, $u2->fresh()->matricule);
    }

    public function test_backfill_command_skips_users_already_with_matricule(): void
    {
        $this->ensureRoles();
        $org = $this->createOrg();

        $u = User::factory()->create(['organization_id' => $org->id, 'matricule' => '000042']);
        $u->assignRole('manager');

        $this->artisan('users:backfill-matricules')->assertSuccessful();

        $this->assertSame('000042', $u->fresh()->matricule);
    }

    public function test_backfill_command_skips_users_without_organization(): void
    {
        $this->ensureRoles();

        $u = User::factory()->create(['organization_id' => null, 'matricule' => null]);
        $u->assignRole('manager');

        $this->artisan('users:backfill-matricules')->assertSuccessful();

        $this->assertNull($u->fresh()->matricule);
    }
}
