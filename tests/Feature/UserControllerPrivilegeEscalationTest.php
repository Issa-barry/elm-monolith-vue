<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * UserController::store()/update() validaient `role` contre STAFF_ROLES (qui inclut
 * `super_admin`) sans jamais vérifier que l'ACTEUR l'est lui-même — n'importe quel utilisateur
 * autorisé à modifier des comptes (permission users.update/users.create) pouvait donc
 * s'attribuer ou attribuer `super_admin` à un tiers. Corrigé le 2026-08-21 — cf. plan §4.
 */
class UserControllerPrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('users.update');
        $this->attachSite($org, $user);

        return $user;
    }

    private function superAdmin(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');
        $this->attachSite($org, $user);

        return $user;
    }

    private function attachSite(Organization $org, User $user): Site
    {
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Site Test', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $site;
    }

    /**
     * Le cache de permissions Spatie (config('permission.cache.store') = 'default', driver
     * `array` en test) persiste pour la durée du PROCESSUS PHPUnit entier, pas juste la
     * transaction RefreshDatabase — un rôle créé dans un test précédent puis "vu" en cache peut
     * fausser hasRole() dans un test suivant si le cache n'est pas explicitement invalidé, cf.
     * RoleController qui le fait déjà après chaque mutation de rôle.
     */
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_entreprise_cannot_assign_super_admin_via_store(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $site = $this->attachSite($org, $admin);

        $this->actingAs($admin)->post('/backoffice/users', [
            'prenom' => 'Test', 'nom' => 'User', 'telephone' => '+224620000099',
            'role' => 'super_admin', 'site_id' => $site->id,
            'password' => 'Sup3rSecret1', 'password_confirmation' => 'Sup3rSecret1',
        ])->assertForbidden();

        $this->assertSame(0, User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->count());
    }

    public function test_admin_entreprise_cannot_assign_super_admin_via_update(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('admin_entreprise');
        $site = $this->attachSite($org, $target);

        $this->actingAs($admin)->put("/backoffice/users/{$target->id}", [
            'prenom' => $target->prenom, 'nom' => $target->nom, 'email' => null,
            'telephone' => $target->telephone, 'role' => 'super_admin', 'site_id' => $site->id,
            'password' => '', 'password_confirmation' => '',
        ])->assertForbidden();

        $this->assertFalse($target->fresh()->hasRole('super_admin'));
    }

    public function test_super_admin_can_assign_super_admin(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = $this->superAdmin($org);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('admin_entreprise');
        $site = $this->attachSite($org, $target);

        $this->actingAs($superAdmin)->put("/backoffice/users/{$target->id}", [
            'prenom' => $target->prenom, 'nom' => $target->nom, 'email' => null,
            'telephone' => $target->telephone, 'role' => 'super_admin', 'site_id' => $site->id,
            'password' => '', 'password_confirmation' => '',
        ])->assertRedirect();

        $this->assertTrue($target->fresh()->hasRole('super_admin'));
    }

    public function test_admin_entreprise_can_still_assign_admin_entreprise(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $target = User::factory()->create(['organization_id' => $org->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $target->assignRole('manager');
        $site = $this->attachSite($org, $target);

        $this->actingAs($admin)->put("/backoffice/users/{$target->id}", [
            'prenom' => $target->prenom, 'nom' => $target->nom, 'email' => null,
            'telephone' => $target->telephone, 'role' => 'admin_entreprise', 'site_id' => $site->id,
            'password' => '', 'password_confirmation' => '',
        ])->assertRedirect();

        $this->assertTrue($target->fresh()->hasRole('admin_entreprise'));
    }
}
