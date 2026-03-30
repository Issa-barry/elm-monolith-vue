<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VehiculeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    private function userWithPermissions(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach (['vehicules.read', 'vehicules.create', 'vehicules.update', 'vehicules.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['vehicules.read', 'vehicules.create', 'vehicules.update', 'vehicules.delete']);

        return $user;
    }

    private function makeVehicule(Organization $org): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('vehicules.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('vehicules.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('vehicules.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('vehicules.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_vehicule_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion 01',
                'immatriculation' => 'RC-001-GN',
                'type_vehicule' => 'camion',
                'proprietaire_id' => $proprietaire->id,
                'capacite_packs' => 200,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.index'));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('vehicules.store'), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule', 'proprietaire_id']);
    }

    public function test_store_fails_with_invalid_type_vehicule(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Test',
                'immatriculation' => 'RC-002-GN',
                'type_vehicule' => 'avion',
                'proprietaire_id' => $proprietaire->id,
            ])
            ->assertSessionHasErrors('type_vehicule');
    }

    public function test_store_fails_with_proprietaire_from_other_org(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Test',
                'immatriculation' => 'RC-003-GN',
                'type_vehicule' => 'camion',
                'proprietaire_id' => $proprietaire->id,
            ])
            ->assertSessionHasErrors('proprietaire_id');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $vehicule = $this->makeVehicule($org);

        $this->actingAs($user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($otherOrg);

        $this->actingAs($user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_vehicule_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $vehicule = $this->makeVehicule($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => 'Camion modifie',
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule' => 'camion',
                'proprietaire_id' => $proprietaire->id,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.index'));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $vehicule = $this->makeVehicule($org);

        $this->actingAs($user)
            ->put(route('vehicules.update', $vehicule), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule', 'proprietaire_id']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_vehicule_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $vehicule = $this->makeVehicule($org);

        $this->actingAs($user)
            ->delete(route('vehicules.destroy', $vehicule))
            ->assertRedirect(route('vehicules.index'));

        $this->assertSoftDeleted('vehicules', ['id' => $vehicule->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($otherOrg);

        $this->actingAs($user)
            ->delete(route('vehicules.destroy', $vehicule))
            ->assertStatus(403);
    }

    // ── livreur assignment ────────────────────────────────────────────────────

    public function test_store_can_assign_livreur_from_same_org(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Moto 01',
                'immatriculation' => 'MO-001-GN',
                'type_vehicule' => 'moto',
                'proprietaire_id' => $proprietaire->id,
                'livreur_principal_id' => $livreur->id,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.index'));

        $this->assertDatabaseHas('vehicules', [
            'livreur_principal_id' => $livreur->id,
            'organization_id' => $org->id,
        ]);
    }
}
