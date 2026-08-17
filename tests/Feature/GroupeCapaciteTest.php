<?php

namespace Tests\Feature;

use App\Models\GroupeCapacite;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class GroupeCapaciteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read', 'vehicules.create', 'vehicules.update', 'vehicules.delete']);
    }

    private function makeGroupe(array $overrides = []): GroupeCapacite
    {
        return GroupeCapacite::create(array_merge([
            'organization_id' => $this->org->id,
            'nom' => 'Sachets',
        ], $overrides));
    }

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('groupes-capacite.index'))
            ->assertStatus(200);
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('groupes-capacite.index'))
            ->assertStatus(403);
    }

    public function test_store_creates_groupe(): void
    {
        $this->actingAs($this->user)
            ->post(route('groupes-capacite.store'), ['nom' => 'Bouteilles'])
            ->assertRedirect();

        $this->assertDatabaseHas('groupes_capacite', [
            'organization_id' => $this->org->id,
            'nom' => 'Bouteilles',
        ]);
    }

    public function test_store_fails_with_duplicate_nom_in_same_org(): void
    {
        $this->makeGroupe(['nom' => 'Sachets']);

        $this->actingAs($this->user)
            ->post(route('groupes-capacite.store'), ['nom' => 'Sachets'])
            ->assertSessionHasErrors('nom');
    }

    public function test_update_renames_groupe(): void
    {
        $groupe = $this->makeGroupe();

        $this->actingAs($this->user)
            ->put(route('groupes-capacite.update', $groupe), ['nom' => 'Sachets 25'])
            ->assertRedirect();

        $this->assertDatabaseHas('groupes_capacite', ['id' => $groupe->id, 'nom' => 'Sachets 25']);
    }

    public function test_destroy_deletes_unused_groupe(): void
    {
        $groupe = $this->makeGroupe();

        $this->actingAs($this->user)
            ->delete(route('groupes-capacite.destroy', $groupe))
            ->assertRedirect();

        $this->assertDatabaseMissing('groupes_capacite', ['id' => $groupe->id]);
    }

    public function test_destroy_refuse_si_groupe_utilise_par_un_produit(): void
    {
        $groupe = $this->makeGroupe();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        Produit::create([
            'organization_id' => $this->org->id,
            'groupe_capacite_id' => $groupe->id,
            'nom' => 'Pack de 25 sachets',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->value('id'),
            'statut' => 'actif',
        ]);

        $this->actingAs($this->user)
            ->delete(route('groupes-capacite.destroy', $groupe))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('groupes_capacite', ['id' => $groupe->id]);
    }

    public function test_destroy_refuse_si_groupe_utilise_par_une_capacite_vehicule(): void
    {
        $groupe = $this->makeGroupe();
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $type->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $groupe->id, 'capacite_max' => 1700]);

        $this->actingAs($this->user)
            ->delete(route('groupes-capacite.destroy', $groupe))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('groupes_capacite', ['id' => $groupe->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $groupe = GroupeCapacite::create(['organization_id' => $otherOrg->id, 'nom' => 'Sachets']);

        $this->actingAs($this->user)
            ->delete(route('groupes-capacite.destroy', $groupe))
            ->assertStatus(403);
    }
}
