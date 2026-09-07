<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verrou de la refonte rôles/permissions (2026-09-06) : `Role::orderBy('name')->get()` sans
 * filtre exposait ici les rôles personnalisés de TOUTES les organisations de la plateforme —
 * même bug que DepenseParametrageController::edit() avant sa propre correction.
 */
class StockAjustementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(string $permission, Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::findOrCreate($permission, 'web');

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permission);

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Agence Test', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_edit_config_nexpose_pas_un_role_dune_autre_organisation(): void
    {
        $org = Organization::factory()->create();
        $user = $this->adminWith('parametres.update', $org);

        $autreOrg = Organization::factory()->create();
        Role::create(['name' => 'chef_agence', 'label' => "Chef d'agence", 'guard_name' => 'web', 'organization_id' => $autreOrg->id]);

        $response = $this->actingAs($user)
            ->get(route('settings.produits'))
            ->assertOk();

        $roleNames = array_column($response->original->getData()['page']['props']['config'], 'role_name');

        $this->assertNotContains('chef_agence', $roleNames);
    }
}
