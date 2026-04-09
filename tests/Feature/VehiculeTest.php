<?php

namespace Tests\Feature;

use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class VehiculeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read', 'vehicules.create', 'vehicules.update', 'vehicules.delete']);
    }

    private function makeVehicule(Organization $org): Vehicule
    {
        $equipe = $this->makeEquipe($org);

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $equipe->proprietaire_id,
            'equipe_livraison_id' => $equipe->id,
        ]);
    }

    private function makeEquipe(Organization $org, ?Proprietaire $proprietaire = null): EquipeLivraison
    {
        $owner = $proprietaire ?? Proprietaire::factory()->create(['organization_id' => $org->id]);

        return EquipeLivraison::create([
            'organization_id' => $org->id,
            'proprietaire_id' => $owner->id,
            'nom' => 'Équipe Test '.uniqid(),
            'is_active' => true,
            'taux_commission_proprietaire' => 60,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('vehicules.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('vehicules.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('vehicules.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('vehicules.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_vehicule_and_redirects(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($this->org, $proprietaire);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion 01',
                'immatriculation' => 'RC-001-GN',
                'type_vehicule' => 'camion',
                'equipe_livraison_id' => $equipe->id,
                'capacite_packs' => 200,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.index'));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'equipe_livraison_id' => $equipe->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule', 'equipe_livraison_id']);
    }

    public function test_store_fails_with_invalid_type_vehicule(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($this->org, $proprietaire);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Test',
                'immatriculation' => 'RC-002-GN',
                'type_vehicule' => 'avion',
                'equipe_livraison_id' => $equipe->id,
            ])
            ->assertSessionHasErrors('type_vehicule');
    }

    public function test_store_fails_with_equipe_from_other_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);
        $equipe = $this->makeEquipe($otherOrg, $proprietaire);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Test',
                'immatriculation' => 'RC-003-GN',
                'type_vehicule' => 'camion',
                'equipe_livraison_id' => $equipe->id,
            ])
            ->assertSessionHasErrors('equipe_livraison_id');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($otherOrg);

        $this->actingAs($this->user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_vehicule_and_redirects(): void
    {
        $vehicule = $this->makeVehicule($this->org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($this->org, $proprietaire);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => 'Camion modifie',
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule' => 'camion',
                'equipe_livraison_id' => $equipe->id,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'proprietaire_id' => $proprietaire->id,
            'equipe_livraison_id' => $equipe->id,
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule', 'equipe_livraison_id']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_vehicule_and_redirects(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->delete(route('vehicules.destroy', $vehicule))
            ->assertRedirect(route('vehicules.index'));

        $this->assertSoftDeleted('vehicules', ['id' => $vehicule->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('vehicules.destroy', $vehicule))
            ->assertStatus(403);
    }

    // ── equipe assignment ─────────────────────────────────────────────────────

    public function test_store_can_assign_equipe_from_same_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 60,
        ]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Moto 01',
                'immatriculation' => 'MO-001-GN',
                'type_vehicule' => 'moto',
                'equipe_livraison_id' => $equipe->id,
                'taux_commission_proprietaire' => 100,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.index'));

        $this->assertDatabaseHas('vehicules', [
            'equipe_livraison_id' => $equipe->id,
            'proprietaire_id' => $proprietaire->id,
            'organization_id' => $this->org->id,
        ]);
    }
}
