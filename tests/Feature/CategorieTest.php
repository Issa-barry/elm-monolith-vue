<?php

namespace Tests\Feature;

use App\Models\Categorie;
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

class CategorieTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['categories.read', 'categories.create', 'categories.update', 'categories.delete']);
    }

    private function makeCategorie(Organization $org, array $overrides = []): Categorie
    {
        return Categorie::create(array_merge([
            'organization_id' => $org->id,
            'nom' => 'Catégorie test',
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.categories.index'))
            ->assertStatus(200);
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('produits.categories.index'))
            ->assertStatus(403);
    }

    public function test_index_ne_retourne_que_les_categories_de_lorganisation(): void
    {
        $this->makeCategorie($this->org, ['nom' => 'À moi']);
        $otherOrg = Organization::factory()->create();
        $this->makeCategorie($otherOrg, ['nom' => 'Pas à moi']);

        $response = $this->actingAs($this->user)->get(route('produits.categories.index'));

        $response->assertStatus(200);
        $categories = $response->original->getData()['page']['props']['categories'];
        $this->assertCount(1, $categories);
        $this->assertSame('À moi', $categories[0]['nom']);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_categorie(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.categories.store'), ['nom' => 'Boissons'])
            ->assertRedirect();

        $this->assertDatabaseHas('categories', [
            'organization_id' => $this->org->id,
            'nom' => 'Boissons',
            'statut' => 'actif',
        ]);
    }

    public function test_store_avec_parent(): void
    {
        $parent = $this->makeCategorie($this->org, ['nom' => 'Vêtements']);

        $this->actingAs($this->user)
            ->post(route('produits.categories.store'), ['nom' => 'T-shirts', 'parent_id' => $parent->id])
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['nom' => 'T-shirts', 'parent_id' => $parent->id]);
    }

    public function test_store_flashes_created_categorie_id(): void
    {
        // Consommé par CreateCategorieModal.vue pour sélectionner automatiquement la
        // catégorie tout juste créée depuis le formulaire Produit, sans navigation.
        $response = $this->actingAs($this->user)
            ->post(route('produits.categories.store'), ['nom' => 'Boissons']);

        $categorie = Categorie::where('nom', 'Boissons')->firstOrFail();
        $response->assertSessionHas('created_categorie_id', $categorie->id);
    }

    public function test_store_fails_without_nom(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.categories.store'), [])
            ->assertSessionHasErrors('nom');
    }

    public function test_store_fails_avec_parent_dune_autre_organisation(): void
    {
        $otherOrg = Organization::factory()->create();
        $parentAutreOrg = $this->makeCategorie($otherOrg);

        $this->actingAs($this->user)
            ->post(route('produits.categories.store'), ['nom' => 'Test', 'parent_id' => $parentAutreOrg->id])
            ->assertSessionHasErrors('parent_id');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_categorie(): void
    {
        $categorie = $this->makeCategorie($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.categories.update', $categorie), ['nom' => 'Nouveau nom'])
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['id' => $categorie->id, 'nom' => 'Nouveau nom']);
    }

    public function test_update_refuse_de_se_definir_comme_son_propre_parent(): void
    {
        $categorie = $this->makeCategorie($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.categories.update', $categorie), ['parent_id' => $categorie->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_update_refuse_un_descendant_comme_parent(): void
    {
        $parent = $this->makeCategorie($this->org, ['nom' => 'Parent']);
        $enfant = $this->makeCategorie($this->org, ['nom' => 'Enfant', 'parent_id' => $parent->id]);

        $this->actingAs($this->user)
            ->put(route('produits.categories.update', $parent), ['parent_id' => $enfant->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $categorie = $this->makeCategorie($otherOrg);

        $this->actingAs($this->user)
            ->put(route('produits.categories.update', $categorie), ['nom' => 'Test'])
            ->assertStatus(403);
    }

    // ── toggle ────────────────────────────────────────────────────────────────

    public function test_toggle_desactive_puis_reactive(): void
    {
        $categorie = $this->makeCategorie($this->org);

        $this->actingAs($this->user)
            ->patch(route('produits.categories.toggle', $categorie))
            ->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $categorie->id, 'statut' => 'inactif']);

        $this->actingAs($this->user)
            ->patch(route('produits.categories.toggle', $categorie))
            ->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $categorie->id, 'statut' => 'actif']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_supprime_une_categorie_inutilisee(): void
    {
        $categorie = $this->makeCategorie($this->org);

        $this->actingAs($this->user)
            ->delete(route('produits.categories.destroy', $categorie))
            ->assertRedirect();

        $this->assertSoftDeleted('categories', ['id' => $categorie->id]);
    }

    public function test_destroy_refuse_si_categorie_utilisee_par_un_produit(): void
    {
        $categorie = $this->makeCategorie($this->org);
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        Produit::create([
            'organization_id' => $this->org->id,
            'categorie_id' => $categorie->id,
            'nom' => 'Produit',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->value('id'),
            'statut' => 'actif',
        ]);

        $this->actingAs($this->user)
            ->delete(route('produits.categories.destroy', $categorie))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('categories', ['id' => $categorie->id, 'deleted_at' => null]);
    }

    /**
     * Une catégorie utilisée comme référence de capacité véhicule (vehicule_capacites) ne
     * peut pas être supprimée — même garde-fou que "utilisée par un produit" ci-dessus, cf.
     * Categorie::getIsUsedAttribute() et VehiculeCapaciteService.
     */
    public function test_destroy_refuse_si_categorie_utilisee_par_une_capacite_vehicule(): void
    {
        $categorie = $this->makeCategorie($this->org);
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $type->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $vehicule->capacites()->create([
            'organization_id' => $this->org->id,
            'categorie_id' => $categorie->id,
            'capacite_max' => 100,
        ]);

        $this->actingAs($this->user)
            ->delete(route('produits.categories.destroy', $categorie))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('categories', ['id' => $categorie->id, 'deleted_at' => null]);
    }

    public function test_destroy_refuse_si_categorie_a_des_enfants(): void
    {
        $parent = $this->makeCategorie($this->org, ['nom' => 'Parent']);
        $this->makeCategorie($this->org, ['nom' => 'Enfant', 'parent_id' => $parent->id]);

        $this->actingAs($this->user)
            ->delete(route('produits.categories.destroy', $parent))
            ->assertSessionHasErrors('delete');
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $categorie = $this->makeCategorie($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('produits.categories.destroy', $categorie))
            ->assertStatus(403);
    }
}
