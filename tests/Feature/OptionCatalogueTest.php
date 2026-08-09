<?php

namespace Tests\Feature;

use App\Models\OptionCatalogue;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class OptionCatalogueTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['options.read', 'options.create', 'options.update', 'options.delete']);
    }

    private function makeOption(Organization $org, array $overrides = []): OptionCatalogue
    {
        return OptionCatalogue::create(array_merge([
            'organization_id' => $org->id,
            'nom' => 'Couleur',
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.options.index'))
            ->assertStatus(200);
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('produits.options.index'))
            ->assertStatus(403);
    }

    public function test_index_ne_retourne_que_les_options_de_lorganisation(): void
    {
        $this->makeOption($this->org, ['nom' => 'À moi']);
        $otherOrg = Organization::factory()->create();
        $this->makeOption($otherOrg, ['nom' => 'Pas à moi']);

        $response = $this->actingAs($this->user)->get(route('produits.options.index'));

        $response->assertStatus(200);
        $options = $response->original->getData()['page']['props']['options'];
        $this->assertCount(1, $options);
        $this->assertSame('À moi', $options[0]['nom']);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_option(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.options.store'), ['nom' => 'Taille'])
            ->assertRedirect();

        $this->assertDatabaseHas('option_catalogues', [
            'organization_id' => $this->org->id,
            'nom' => 'Taille',
        ]);
    }

    public function test_store_avec_valeurs_initiales(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.options.store'), [
                'nom' => 'Couleur',
                'valeurs' => ['Noir', 'Blanc'],
            ])
            ->assertRedirect();

        $option = OptionCatalogue::where('nom', 'Couleur')->firstOrFail();
        $this->assertCount(2, $option->valeurs);
        $this->assertSame(['Noir', 'Blanc'], $option->valeurs->pluck('valeur')->all());
    }

    public function test_store_flashes_created_option_catalogue_id(): void
    {
        // Consommé par CreateOptionCatalogueModal.vue pour sélectionner automatiquement
        // l'option tout juste créée depuis le formulaire Produit, sans navigation.
        $response = $this->actingAs($this->user)
            ->post(route('produits.options.store'), ['nom' => 'Taille']);

        $option = OptionCatalogue::where('nom', 'Taille')->firstOrFail();
        $response->assertSessionHas('created_option_catalogue_id', $option->id);
    }

    public function test_store_fails_without_nom(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.options.store'), [])
            ->assertSessionHasErrors('nom');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_option(): void
    {
        $option = $this->makeOption($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.options.update', $option), ['nom' => 'Nouveau nom'])
            ->assertRedirect();

        $this->assertDatabaseHas('option_catalogues', ['id' => $option->id, 'nom' => 'Nouveau nom']);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $option = $this->makeOption($otherOrg);

        $this->actingAs($this->user)
            ->put(route('produits.options.update', $option), ['nom' => 'Test'])
            ->assertStatus(403);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_supprime_une_option(): void
    {
        $option = $this->makeOption($this->org);

        $this->actingAs($this->user)
            ->delete(route('produits.options.destroy', $option))
            ->assertRedirect();

        $this->assertSoftDeleted('option_catalogues', ['id' => $option->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $option = $this->makeOption($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('produits.options.destroy', $option))
            ->assertStatus(403);
    }

    // ── valeurs ───────────────────────────────────────────────────────────────

    public function test_store_valeur_ajoute_une_valeur_au_catalogue(): void
    {
        $option = $this->makeOption($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.options.valeurs.store', $option), ['valeur' => 'Rouge'])
            ->assertRedirect();

        $this->assertDatabaseHas('option_catalogue_valeurs', [
            'option_catalogue_id' => $option->id,
            'valeur' => 'Rouge',
        ]);
    }

    public function test_store_valeur_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $option = $this->makeOption($otherOrg);

        $this->actingAs($this->user)
            ->post(route('produits.options.valeurs.store', $option), ['valeur' => 'Rouge'])
            ->assertStatus(403);
    }

    public function test_destroy_valeur_supprime_la_valeur(): void
    {
        $option = $this->makeOption($this->org);
        $valeur = $option->valeurs()->create(['valeur' => 'Noir', 'position' => 0]);

        $this->actingAs($this->user)
            ->delete(route('produits.options.valeurs.destroy', [$option, $valeur]))
            ->assertRedirect();

        $this->assertDatabaseMissing('option_catalogue_valeurs', ['id' => $valeur->id]);
    }
}
