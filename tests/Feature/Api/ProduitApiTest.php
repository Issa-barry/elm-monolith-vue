<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ProduitApiTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['produits.read', 'produits.create', 'produits.update', 'produits.delete']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeProduit(Organization $org, array $overrides = []): Produit
    {
        return Produit::create(array_merge([
            'organization_id' => $org->id,
            'nom' => 'Produit test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 500,
            'qte_stock' => 50,
            'is_alerte' => false,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_produits_for_authorized_user(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        // Liste vide
        $this->getJson(route('api.backoffice.produits.index'))
            ->assertOk()
            ->assertJson(['data' => []]);

        // Liste avec produits
        $produit = $this->makeProduit($this->org);

        $response = $this->getJson(route('api.backoffice.produits.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $response->assertJsonFragment([
            'id' => $produit->id,
            'nom' => $produit->nom,
            'type' => 'materiel',
            'statut' => 'actif',
        ]);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson(route('api.backoffice.produits.index'))
            ->assertUnauthorized();
    }

    public function test_index_requires_permission(): void
    {
        $userWithout = $this->makeUserWithPermissions($this->org, []);
        Sanctum::actingAs($userWithout, ['*']);

        $this->getJson(route('api.backoffice.produits.index'))
            ->assertForbidden();
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_produit(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org);

        $this->getJson(route('api.backoffice.produits.show', $produit))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $produit->id,
                'nom' => $produit->nom,
                'type' => 'materiel',
                'type_label' => 'Matériel',
                'type_has_stock' => true,
                'statut' => 'actif',
                'statut_label' => 'Actif',
                'qte_stock' => 50,
                'is_alerte' => false,
            ]);
    }

    public function test_show_returns_404_for_other_org(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->getJson(route('api.backoffice.produits.show', $produit))
            ->assertForbidden();
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_produit(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->postJson(route('api.backoffice.produits.store'), [
            'nom' => 'Nouveau produit',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 1500,
            'qte_stock' => 100,
            'is_alerte' => false,
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'nom' => 'Nouveau produit',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1500,
                'qte_stock' => 100,
            ]);

        $this->assertDatabaseHas('produits', [
            'organization_id' => $this->org->id,
            'nom' => 'Nouveau produit',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.backoffice.produits.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nom', 'type', 'statut']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_updates_produit(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org);

        $this->putJson(route('api.backoffice.produits.update', $produit), [
            'nom' => 'Produit modifié',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 800,
        ])
            ->assertOk()
            ->assertJsonFragment([
                'nom' => 'Produit modifié',
                'prix_achat' => 800,
            ]);

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'prix_achat' => 800,
        ]);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_produit(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org);

        $this->deleteJson(route('api.backoffice.produits.destroy', $produit))
            ->assertNoContent();

        $this->assertSoftDeleted('produits', ['id' => $produit->id]);
    }

    // ── ajuster-stock ─────────────────────────────────────────────────────────

    public function test_ajuster_stock_augmente(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org, ['qte_stock' => 50]);

        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'augmenter' => 20,
        ])
            ->assertOk()
            ->assertJsonFragment(['qte_stock' => 70]);

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

    public function test_ajuster_stock_diminue(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org, ['qte_stock' => 50]);

        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'diminuer' => 15,
        ])
            ->assertOk()
            ->assertJsonFragment(['qte_stock' => 35]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'type' => 'sortie',
            'quantite' => 15,
            'stock_avant' => 50,
            'stock_apres' => 35,
        ]);
    }

    public function test_ajuster_stock_rejects_both_fields(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org);

        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'augmenter' => 10,
            'diminuer' => 5,
            'motif_type' => 'correction_stock',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('augmenter');
    }

    public function test_ajuster_stock_rejects_if_diminuer_exceeds_stock(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org, ['qte_stock' => 10]);

        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'diminuer' => 100,
            'motif_type' => 'perte',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('diminuer');

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 10,
        ]);
    }

    public function test_ajuster_stock_rejects_service_type(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org, ['type' => 'service']);

        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'augmenter' => 5,
        ])
            ->assertStatus(422);
    }
}
