<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ParametreTemplateDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'parametres.update', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('parametres.update');

        return $user;
    }

    private function userWithoutPermission(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    public function test_download_template_redirects_unauthenticated_user(): void
    {
        $this->get(route('parametres.templates.download', ['template' => 'produits']))
            ->assertRedirect(route('login'));
    }

    public function test_download_template_returns_403_without_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);

        $this->actingAs($user)
            ->get(route('parametres.templates.download', ['template' => 'produits']))
            ->assertStatus(403);
    }

    public function test_download_produits_template_returns_excel_file(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->get(route('parametres.templates.download', ['template' => 'produits']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.ms-excel; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="template-produits.xls"')
            ->assertSee('nom')
            ->assertSee('code_fournisseur')
            ->assertSee('seuil_alerte_stock');
    }

    public function test_download_vehicules_pack_contains_3_sheets(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->get(route('parametres.templates.download', ['template' => 'vehicules-pack']))
            ->assertOk()
            ->assertSee('Worksheet ss:Name="proprietaires"', false)
            ->assertSee('Worksheet ss:Name="livreurs"', false)
            ->assertSee('Worksheet ss:Name="vehicules"', false)
            ->assertSee('equipe_livraison_id');
    }

    public function test_download_unknown_template_returns_404(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->get(route('parametres.templates.download', ['template' => 'unknown-template']))
            ->assertStatus(404);
    }
}
