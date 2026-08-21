<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * routes/web.php enveloppait la quasi-totalité de /backoffice/* avec
 * `role:super_admin|admin_entreprise|manager|commerciale|comptable` — un rôle personnalisé
 * d'organisation (créé via RoleController) n'accédait donc à AUCUNE page du back-office, quelles
 * que soient ses permissions. Remplacé par le middleware EnsureIsStaffAccount (exclusion des 3
 * rôles strictement externes) — cf. plan §4.
 */
class EnsureIsStaffAccountTest extends TestCase
{
    use RefreshDatabase;

    /** Le cache de permissions Spatie persiste pour tout le processus PHPUnit — cf. RoleController. */
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole(Organization $org, string $roleName): User
    {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($roleName);

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Site Test', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_a_custom_organization_role_can_access_the_backoffice_dashboard(): void
    {
        $org = Organization::factory()->create();
        Role::query()->create(['name' => 'gerant_depot', 'guard_name' => 'web', 'organization_id' => $org->id, 'label' => 'Gérant de dépôt']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('gerant_depot');
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Site Test', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->get('/backoffice/dashboard')->assertOk();
    }

    public function test_a_client_account_is_refused_the_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRole($org, 'client');

        $this->actingAs($user)->get('/backoffice/dashboard')->assertForbidden();
    }

    public function test_a_proprietaire_account_is_refused_the_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRole($org, 'proprietaire');

        $this->actingAs($user)->get('/backoffice/dashboard')->assertForbidden();
    }

    public function test_a_livreur_account_is_refused_the_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRole($org, 'livreur');

        $this->actingAs($user)->get('/backoffice/dashboard')->assertForbidden();
    }

    public function test_the_five_historical_staff_roles_still_access_the_backoffice(): void
    {
        foreach (['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable'] as $roleName) {
            $user = $this->userWithRole(Organization::factory()->create(), $roleName);
            $this->actingAs($user)->get('/backoffice/dashboard')->assertOk();
        }
    }
}
