<?php

namespace Tests\Feature;

use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CRUD de fonctions RH, strictement isolé par organisation — aucune fonction système/partagée
 * (décision finale du 2026-08-21), mirroring du gating de RoleController.
 */
class FonctionRhTest extends TestCase
{
    use RefreshDatabase;

    /** Le cache de permissions Spatie persiste pour tout le processus PHPUnit — cf. RoleController. */
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $this->attachSite($org, $user);

        return $user;
    }

    private function nonAdmin(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $this->attachSite($org, $user);

        return $user;
    }

    private function attachSite(Organization $org, User $user): void
    {
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Site Test', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    public function test_index_returns_200_for_admin(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $this->actingAs($admin)->get('/backoffice/fonctions-rh')->assertOk();
    }

    public function test_index_returns_403_for_non_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->nonAdmin($org);

        $this->actingAs($user)->get('/backoffice/fonctions-rh')->assertForbidden();
    }

    public function test_store_creates_a_fonction_scoped_to_the_organization(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $this->actingAs($admin)
            ->post('/backoffice/fonctions-rh', ['libelle' => 'Gérant de dépôt'])
            ->assertRedirect();

        $fonction = FonctionRh::where('organization_id', $org->id)->sole();
        $this->assertSame('Gérant de dépôt', $fonction->libelle);
        $this->assertNotNull($fonction->code);
        $this->assertTrue($fonction->is_active);
    }

    public function test_store_rejects_a_duplicate_libelle_in_the_same_organization(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        FonctionRh::create(['organization_id' => $org->id, 'libelle' => 'Gérant de dépôt', 'code' => 'GDE', 'is_active' => true]);

        $this->actingAs($admin)
            ->post('/backoffice/fonctions-rh', ['libelle' => 'Gérant de dépôt'])
            ->assertSessionHasErrors('libelle');
    }

    public function test_store_allows_the_same_libelle_in_a_different_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        FonctionRh::create(['organization_id' => $orgA->id, 'libelle' => 'Gérant de dépôt', 'code' => 'GDE', 'is_active' => true]);
        $adminB = $this->admin($orgB);

        $this->actingAs($adminB)
            ->post('/backoffice/fonctions-rh', ['libelle' => 'Gérant de dépôt'])
            ->assertRedirect();

        $this->assertSame(2, FonctionRh::where('libelle', 'Gérant de dépôt')->count());
    }

    public function test_store_returns_403_for_non_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->nonAdmin($org);

        $this->actingAs($user)
            ->post('/backoffice/fonctions-rh', ['libelle' => 'Gérant de dépôt'])
            ->assertForbidden();
    }

    public function test_update_modifies_the_libelle(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $fonction = FonctionRh::create(['organization_id' => $org->id, 'libelle' => 'Gérant', 'code' => 'GER', 'is_active' => true]);

        $this->actingAs($admin)
            ->put("/backoffice/fonctions-rh/{$fonction->id}", ['libelle' => 'Gérant de dépôt'])
            ->assertRedirect();

        $this->assertSame('Gérant de dépôt', $fonction->fresh()->libelle);
    }

    public function test_update_returns_403_for_a_fonction_from_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminB = $this->admin($orgB);
        $fonction = FonctionRh::create(['organization_id' => $orgA->id, 'libelle' => 'Gérant', 'code' => 'GER', 'is_active' => true]);

        $this->actingAs($adminB)
            ->put("/backoffice/fonctions-rh/{$fonction->id}", ['libelle' => 'Autre'])
            ->assertForbidden();
    }

    public function test_store_flashes_created_fonction_rh_id_for_inline_selection(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $response = $this->actingAs($admin)
            ->post('/backoffice/fonctions-rh', ['libelle' => 'Gérant de dépôt']);

        $fonction = FonctionRh::where('organization_id', $org->id)->sole();
        $response->assertSessionHas('created_fonction_rh_id', $fonction->id);
    }

    /** Désactivation, jamais suppression : cf. docblock de FonctionRhController — pas de route destroy. */
    public function test_toggle_deactivates_then_reactivates_a_fonction(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $fonction = FonctionRh::create(['organization_id' => $org->id, 'libelle' => 'Gérant', 'code' => 'GER', 'is_active' => true]);

        $this->actingAs($admin)->patch("/backoffice/fonctions-rh/{$fonction->id}/toggle")->assertRedirect();
        $this->assertFalse($fonction->fresh()->is_active);

        $this->actingAs($admin)->patch("/backoffice/fonctions-rh/{$fonction->id}/toggle")->assertRedirect();
        $this->assertTrue($fonction->fresh()->is_active);
    }

    public function test_a_deactivated_fonction_already_used_by_an_employee_is_never_deleted(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $fonction = FonctionRh::create(['organization_id' => $org->id, 'libelle' => 'Gérant', 'code' => 'GER', 'is_active' => true]);

        Employe::create([
            'organization_id' => $org->id,
            'personne_id' => Personne::factory()->create(['organization_id' => $org->id])->id,
            'matricule' => 'EMP-001',
            'type_employe' => 'interne',
            'fonction_rh_id' => $fonction->id,
            'statut' => 'actif',
        ]);

        $this->actingAs($admin)->patch("/backoffice/fonctions-rh/{$fonction->id}/toggle");

        $this->assertNotNull(FonctionRh::find($fonction->id), 'la fonction doit rester en base, seulement désactivée');
        $this->assertFalse($fonction->fresh()->is_active);
    }
}
