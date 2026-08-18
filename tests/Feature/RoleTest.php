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

    // RoleController::index()/edit() n'exigent pas une permission précise mais gate sur
    // isAdmin() (super_admin|admin_entreprise) : un rôle non-admin comme 'manager' est donc
    // requis ici pour obtenir un vrai refus, une permission-only admin_entreprise ne suffit pas.
    private function userWithoutPermission(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $this->attachSite($org, $user);

        return $user;
    }

    // Une organisation sans aucun site force l'onboarding pour tout rôle staff, super_admin
    // compris (cf. AuthRedirects::needsOnboarding, middleware EnsureOrganizationHasSite) : sans
    // site, ces requêtes back-office sont redirigées avant même d'atteindre le contrôleur testé.
    private function attachSite(Organization $org, User $user): void
    {
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
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

    public function test_index_never_lists_a_role_from_another_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $autreOrg = Organization::factory()->create();
        Role::create(['name' => 'role_autre_org', 'label' => 'Rôle autre org', 'guard_name' => 'web', 'organization_id' => $autreOrg->id]);

        $response = $this->actingAs($user)->get(route('roles.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where(
            'roles',
            fn ($roles) => collect($roles)->doesntContain(fn ($r) => $r['name'] === 'role_autre_org')
        ));
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

    public function test_edit_refuses_a_role_from_another_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $autreOrg = Organization::factory()->create();
        $role = Role::create(['name' => 'role_autre_org', 'label' => 'Rôle autre org', 'guard_name' => 'web', 'organization_id' => $autreOrg->id]);

        $this->actingAs($user)
            ->get(route('roles.edit', $role))
            ->assertStatus(403);
    }

    // ── update : permissions ─────────────────────────────────────────────────

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
        $this->attachSite($org, $user);

        $role = Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), ['permissions' => []])
            ->assertStatus(403);
    }

    public function test_update_as_super_admin_can_set_users_permissions(): void
    {
        $org = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.create', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');
        $this->attachSite($org, $user);

        $role = Role::firstOrCreate(['name' => 'editeur', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), [
                'permissions' => ['users.read', 'users.create'],
            ])
            ->assertRedirect();

        $this->assertTrue($role->fresh()->hasPermissionTo('users.read'));
    }

    public function test_update_attaches_permissions_created_via_this_crud(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        Permission::firstOrCreate(['name' => 'ventes.read', 'guard_name' => 'web']);

        $role = Role::create(['name' => 'chef_agence', 'label' => 'Chef d\'agence', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $this->actingAs($user)
            ->put(route('roles.update', $role), ['permissions' => ['ventes.read']])
            ->assertRedirect();

        $this->assertTrue($role->fresh()->hasPermissionTo('ventes.read'));
    }

    // ── update : super_admin, seul rôle protégé ──────────────────────────────

    public function test_update_returns_back_with_success_for_super_admin_role(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $superAdminRole), ['permissions' => []])
            ->assertRedirect();
    }

    public function test_update_cannot_change_label_of_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'], ['label' => 'Super administrateur']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), ['label' => 'Nouveau nom', 'permissions' => []])
            ->assertSessionHasErrors('label');

        $this->assertSame('Super administrateur', $role->fresh()->label);
    }

    public function test_update_cannot_change_code_of_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'], ['code' => 'SA']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), ['code' => 'XX', 'permissions' => []])
            ->assertSessionHasErrors('code');

        $this->assertSame('SA', $role->fresh()->code);
    }

    // ── update : admin_entreprise n'est plus un rôle système protégé ─────────

    public function test_update_allows_changing_label_and_code_of_admin_entreprise(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->put(route('roles.update', $role), [
                'label' => 'Direction générale',
                'code' => 'DG',
                'permissions' => [],
            ])
            ->assertRedirect();

        $role->refresh();
        $this->assertSame('Direction générale', $role->label);
        $this->assertSame('DG', $role->code);
        // Le nom technique Spatie, lui, ne change jamais après création (cf. RoleController).
        $this->assertSame('admin_entreprise', $role->name);
    }

    // ── store : génération du nom technique ──────────────────────────────────

    public function test_store_generates_technical_name_from_label(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => 'Président directeur Général'])
            ->assertRedirect();

        $role = Role::where('organization_id', $org->id)->where('name', 'president_directeur_general')->firstOrFail();
        $this->assertSame('Président directeur Général', $role->label);
    }

    public function test_store_rejects_a_functionally_duplicate_name(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        Role::create(['name' => 'chef_agence', 'label' => "Chef d'agence", 'guard_name' => 'web', 'organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => "CHEF D'AGENCE"])
            ->assertSessionHasErrors('label');

        $this->assertSame(1, Role::where('organization_id', $org->id)->where('name', 'chef_agence')->count());
    }

    public function test_store_allows_same_label_in_a_different_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $autreOrg = Organization::factory()->create();
        Role::create(['name' => 'chef_agence', 'label' => "Chef d'agence", 'guard_name' => 'web', 'organization_id' => $autreOrg->id]);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => "Chef d'agence"])
            ->assertRedirect();

        $this->assertNotNull(Role::where('organization_id', $org->id)->where('name', 'chef_agence')->first());
    }

    // ── store : trinôme ───────────────────────────────────────────────────────

    public function test_store_normalizes_a_manually_entered_code_to_uppercase(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => 'Président directeur Général', 'code' => 'pdg'])
            ->assertRedirect();

        $this->assertSame('PDG', Role::where('organization_id', $org->id)->firstOrFail()->code);
    }

    public function test_store_generates_code_automatically_when_left_empty(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => 'Président directeur Général'])
            ->assertRedirect();

        $this->assertSame('PDG', Role::where('organization_id', $org->id)->firstOrFail()->code);
    }

    public function test_store_rejects_a_manually_entered_duplicate_code(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        Role::create(['name' => 'pdg_existant', 'label' => 'PDG existant', 'code' => 'PDG', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => 'Autre rôle', 'code' => 'PDG'])
            ->assertSessionHasErrors('code');
    }

    public function test_store_rejects_a_duplicate_code_regardless_of_case(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        Role::create(['name' => 'pdg_existant', 'label' => 'PDG existant', 'code' => 'PDG', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => 'Autre rôle', 'code' => 'pdg'])
            ->assertSessionHasErrors('code');
    }

    public function test_store_extends_generated_code_on_collision_instead_of_duplicating(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        Role::create(['name' => 'rc_existant', 'label' => 'RC existant', 'code' => 'RC', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => 'Responsable Commercial'])
            ->assertRedirect();

        $this->assertSame('RCO', Role::where('organization_id', $org->id)->where('name', 'responsable_commercial')->firstOrFail()->code);
    }

    // ── store : accès / permissions initiales ────────────────────────────────

    public function test_store_creates_a_role_without_any_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => "Chef d'agence", 'code' => 'CA'])
            ->assertRedirect();

        $role = Role::where('organization_id', $org->id)->where('name', 'chef_agence')->firstOrFail();
        $this->assertSame('CA', $role->code);
        $this->assertSame($org->id, $role->organization_id);
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_store_returns_403_if_not_admin_entreprise(): void
    {
        $org = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $this->attachSite($org, $user);

        $this->actingAs($user)
            ->post(route('roles.store'), ['label' => "Chef d'agence"])
            ->assertStatus(403);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_a_custom_role_without_users(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::create(['name' => 'chef_agence', 'label' => "Chef d'agence", 'guard_name' => 'web', 'organization_id' => $org->id]);

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));

        $this->assertNull(Role::find($role->id));
    }

    public function test_destroy_allows_deleting_admin_entreprise_without_users(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        // userWithPermission() attache admin_entreprise à $user lui-même : on le détache d'abord
        // pour tester la suppression d'un admin_entreprise réellement sans utilisateur.
        $user->removeRole('admin_entreprise');
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $role = Role::where('name', 'admin_entreprise')->firstOrFail();

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));

        $this->assertNull(Role::find($role->id));
    }

    public function test_destroy_refuses_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect();

        $this->assertNotNull(Role::find($role->id));
    }

    public function test_destroy_refuses_a_role_still_assigned_to_users(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $role = Role::create(['name' => 'chef_agence', 'label' => "Chef d'agence", 'guard_name' => 'web', 'organization_id' => $org->id]);
        User::factory()->create(['organization_id' => $org->id])->assignRole($role);

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect();

        $this->assertNotNull(Role::find($role->id));
    }

    public function test_destroy_refuses_a_role_from_another_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $autreOrg = Organization::factory()->create();
        $role = Role::create(['name' => 'role_autre_org', 'label' => 'Rôle autre org', 'guard_name' => 'web', 'organization_id' => $autreOrg->id]);

        $this->actingAs($user)
            ->delete(route('roles.destroy', $role))
            ->assertStatus(403);

        $this->assertNotNull(Role::find($role->id));
    }
}
