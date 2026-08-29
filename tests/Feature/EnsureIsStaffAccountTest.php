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
 * que soient ses permissions. Remplacé par le middleware EnsureIsStaffAccount, aujourd'hui basé
 * sur User::hasBackofficeAccess() (règle positive : au moins un rôle non-externe, cf. décision du
 * 26/08/2026 sur le cumul de rôles staff + client/proprietaire/livreur).
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
        return $this->userWithRoles($org, [$roleName]);
    }

    /** @param  string[]  $roleNames */
    private function userWithRoles(Organization $org, array $roleNames): User
    {
        $user = User::factory()->create(['organization_id' => $org->id]);

        foreach ($roleNames as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user->assignRole($roleName);
        }

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

    /** Un compte n'ayant QUE le rôle client (aucun rôle staff) reste refusé. */
    public function test_a_client_only_account_is_refused_the_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRole($org, 'client');

        $this->actingAs($user)->get('/backoffice/dashboard')->assertForbidden();
    }

    /** Un compte n'ayant QUE le rôle proprietaire (aucun rôle staff) reste refusé. */
    public function test_a_proprietaire_only_account_is_refused_the_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRole($org, 'proprietaire');

        $this->actingAs($user)->get('/backoffice/dashboard')->assertForbidden();
    }

    /** Un compte n'ayant QUE le rôle livreur (aucun rôle staff) reste refusé. */
    public function test_a_livreur_only_account_is_refused_the_backoffice(): void
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

    // ── Cumul de rôles (décision du 26/08/2026) ─────────────────────────────────
    // Une personne qui travaille au backoffice ET possède un véhicule (proprietaire)
    // ou commande comme un client normal ne doit pas perdre l'accès au backoffice.

    public function test_admin_entreprise_cumule_avec_proprietaire_garde_le_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRoles($org, ['admin_entreprise', 'proprietaire']);

        $this->actingAs($user)->get('/backoffice/dashboard')->assertOk();
    }

    public function test_manager_cumule_avec_client_garde_le_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRoles($org, ['manager', 'client']);

        $this->actingAs($user)->get('/backoffice/dashboard')->assertOk();
    }

    public function test_comptable_cumule_avec_livreur_garde_le_backoffice(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithRoles($org, ['comptable', 'livreur']);

        $this->actingAs($user)->get('/backoffice/dashboard')->assertOk();
    }

    /** Un rôle personnalisé d'organisation cumulé avec un rôle client garde aussi le backoffice. */
    public function test_custom_organization_role_cumule_avec_proprietaire_garde_le_backoffice(): void
    {
        $org = Organization::factory()->create();
        Role::query()->create(['name' => 'gerant_depot', 'guard_name' => 'web', 'organization_id' => $org->id, 'label' => 'Gérant de dépôt']);
        $user = $this->userWithRoles($org, ['gerant_depot', 'proprietaire']);

        $this->actingAs($user)->get('/backoffice/dashboard')->assertOk();
    }
}
