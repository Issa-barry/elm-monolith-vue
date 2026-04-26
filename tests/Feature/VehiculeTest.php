<?php

namespace Tests\Feature;

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
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
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

        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion 01',
                'immatriculation' => 'RC-001-GN',
                'type_vehicule' => 'camion',
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'capacite_packs' => 200,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'RC-001-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
        ]);
    }

    public function test_store_creates_vehicule_interne(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Moto 01',
                'immatriculation' => 'MO-001-GN',
                'type_vehicule' => 'moto',
                'categorie' => 'interne',
                'capacite_packs' => 50,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'MO-001-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'categorie' => 'interne',
            'proprietaire_id' => null,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule', 'categorie']);
    }

    public function test_store_fails_with_invalid_type_vehicule(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Test',
                'immatriculation' => 'RC-002-GN',
                'type_vehicule' => 'avion',
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
            ])
            ->assertSessionHasErrors('type_vehicule');
    }

    public function test_store_fails_with_proprietaire_from_other_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Test',
                'immatriculation' => 'RC-003-GN',
                'type_vehicule' => 'camion',
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
            ])
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_fails_externe_sans_proprietaire(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Test',
                'immatriculation' => 'RC-004-GN',
                'type_vehicule' => 'camion',
                'categorie' => 'externe',
            ])
            ->assertSessionHasErrors('proprietaire_id');
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
        $nouveauProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => 'Camion modifie',
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule' => 'camion',
                'categorie' => 'externe',
                'proprietaire_id' => $nouveauProprietaire->id,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'proprietaire_id' => $nouveauProprietaire->id,
            'categorie' => 'externe',
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule', 'categorie']);
    }

    public function test_update_autorise_meme_categorie_et_immatriculation(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule' => $vehicule->type_vehicule->value,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));
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

    // ── unicité immatriculation ───────────────────────────────────────────────

    public function test_store_fails_si_immatriculation_deja_utilisee(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-100-GN',
        ]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Doublon',
                'immatriculation' => 'RC-100-GN',
                'type_vehicule' => 'camion',
                'categorie' => 'interne',
            ])
            ->assertSessionHasErrors('immatriculation');
    }

    public function test_update_fails_si_immatriculation_utilisee_par_autre_vehicule(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-999-GN',
        ]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => 'RC-999-GN',
                'type_vehicule' => $vehicule->type_vehicule->value,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
            ])
            ->assertSessionHasErrors('immatriculation');
    }
}
