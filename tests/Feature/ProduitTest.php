<?php

namespace Tests\Feature;

use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ProduitTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['produits.read', 'produits.create', 'produits.update', 'produits.delete']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('produits.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('produits.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_produit_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Rouleau plastique',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'qte_stock' => 100,
                'is_alerte' => false,
            ])
            ->assertRedirect(route('produits.index'));

        $this->assertDatabaseHas('produits', [
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [])
            ->assertSessionHasErrors(['nom', 'type', 'statut']);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Test',
                'type' => 'type_invalide',
                'statut' => 'actif',
            ])
            ->assertSessionHasErrors('type');
    }

    private function makeProduit(Organization $org): Produit
    {
        return Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 500,
            'qte_stock' => 50,
            'is_alerte' => false,
        ]);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->get(route('produits.show', $produit))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->actingAs($this->user)
            ->get(route('produits.show', $produit))
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->get(route('produits.edit', $produit))
            ->assertStatus(200);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_produit_and_redirects(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => 'Nouveau nom produit',
                'type' => 'materiel',
                'statut' => 'actif',
                'is_alerte' => false,
            ])
            ->assertRedirect(route('produits.index'));

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [])
            ->assertSessionHasErrors(['nom', 'type', 'statut']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_produit_and_redirects(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->delete(route('produits.destroy', $produit))
            ->assertRedirect(route('produits.index'));

        $this->assertSoftDeleted('produits', ['id' => $produit->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('produits.destroy', $produit))
            ->assertStatus(403);
    }

    // ── ajuster-stock ─────────────────────────────────────────────────────────

    public function test_ajuster_stock_augmente_le_stock(): void
    {
        $produit = $this->makeProduit($this->org); // qte_stock = 50

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'augmenter'  => 20,
                'motif_type' => 'apres_production',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 70,
        ]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'type' => 'entree',
            'quantite' => 20,
            'stock_avant' => 50,
            'stock_apres' => 70,
        ]);
    }

    public function test_ajuster_stock_diminue_le_stock(): void
    {
        $produit = $this->makeProduit($this->org); // qte_stock = 50

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'diminuer'   => 15,
                'motif_type' => 'perte',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 35,
        ]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'type' => 'sortie',
            'quantite' => 15,
            'stock_avant' => 50,
            'stock_apres' => 35,
        ]);
    }

    public function test_ajuster_stock_enregistre_le_motif(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'augmenter'   => 10,
                'motif_type'  => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'notes' => 'Correction de stock',
        ]);
    }

    public function test_ajuster_stock_echoue_si_deux_champs_renseignes(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'augmenter'  => 10,
                'diminuer'   => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('augmenter');
    }

    public function test_ajuster_stock_echoue_si_aucun_champ_renseigne(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('augmenter');
    }

    public function test_ajuster_stock_echoue_si_quantite_nulle_ou_negative(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'augmenter' => 0,
            ])
            ->assertSessionHasErrors('augmenter');

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'diminuer' => -5,
            ])
            ->assertSessionHasErrors('diminuer');
    }

    public function test_ajuster_stock_echoue_si_retrait_depasse_stock(): void
    {
        $produit = $this->makeProduit($this->org); // qte_stock = 50

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'diminuer'   => 100,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('diminuer');

        // Le stock ne doit pas avoir changé
        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 50,
        ]);
    }

    public function test_ajuster_stock_retourne_403_pour_autre_organisation(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'augmenter' => 10,
            ])
            ->assertStatus(403);
    }

    public function test_ajuster_stock_ne_cree_pas_mouvement_si_validation_echoue(): void
    {
        $produit = $this->makeProduit($this->org);
        $countBefore = MouvementStock::where('produit_id', $produit->id)->count();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'diminuer'   => 9999,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('diminuer');

        $this->assertSame($countBefore, MouvementStock::where('produit_id', $produit->id)->count());
    }
}
