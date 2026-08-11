<?php

namespace Tests\Feature\Api;

use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Produit;
use App\Services\ProduitService;
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

    /**
     * $overrides['qte_stock'] alimente directement le cache produits.qte_stock (sans ligne
     * variante_stocks), reproduisant le scénario "compteur global pas encore ventilé".
     */
    private function makeProduit(Organization $org, array $overrides = []): Produit
    {
        $qteStock = array_key_exists('qte_stock', $overrides) ? $overrides['qte_stock'] : 50;
        unset($overrides['qte_stock']);

        $payload = array_merge([
            'organization_id' => $org->id,
            'nom' => 'Produit test',
            'type' => 'materiel',
            'statut' => 'actif',
            'is_alerte' => false,
        ], $overrides);

        if (($payload['type'] ?? 'materiel') !== 'service' && ! isset($payload['prix_achat'])) {
            $payload['prix_achat'] = 500;
        }

        $produit = app(ProduitService::class)->creer($payload);

        if ($qteStock !== 0) {
            $produit->updateQuietly(['qte_stock' => $qteStock]);
        }

        return $produit->fresh();
    }

    private function varianteId(Produit $produit): string
    {
        return $produit->variantePrincipale()->first()->id;
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

    public function test_show_expose_le_fournisseur(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $fournisseur = Fournisseur::create([
            'organization_id' => $this->org->id,
            'raison_sociale' => 'SOGUIDEP',
            'phone' => '+224620000020',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'is_active' => true,
        ]);
        $produit = $this->makeProduit($this->org, ['fournisseur_id' => $fournisseur->id]);

        $this->getJson(route('api.backoffice.produits.show', $produit))
            ->assertOk()
            ->assertJsonFragment([
                'fournisseur_id' => $fournisseur->id,
                'fournisseur_nom' => 'Soguidep',
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
            'is_alerte' => false,
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'nom' => 'Nouveau produit',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1500,
            ]);

        $this->assertDatabaseHas('produits', [
            'organization_id' => $this->org->id,
            'nom' => 'Nouveau produit',
        ]);
        $this->assertDatabaseHas('produit_variantes', [
            'prix_achat' => 1500,
            'is_default' => true,
        ]);
    }

    public function test_store_creates_produit_avec_fournisseur(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $fournisseur = Fournisseur::create([
            'organization_id' => $this->org->id,
            'raison_sociale' => 'SOGUIDEP',
            'phone' => '+224620000021',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'is_active' => true,
        ]);

        $response = $this->postJson(route('api.backoffice.produits.store'), [
            'nom' => 'Produit avec fournisseur',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 1500,
            'fournisseur_id' => $fournisseur->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('produits', [
            'nom' => 'Produit avec fournisseur',
            'fournisseur_id' => $fournisseur->id,
        ]);
    }

    public function test_store_refuse_un_fournisseur_dune_autre_organisation(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $autreOrg = Organization::factory()->create();
        $fournisseurAilleurs = Fournisseur::create([
            'organization_id' => $autreOrg->id,
            'raison_sociale' => 'Ailleurs',
            'phone' => '+224620000022',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'is_active' => true,
        ]);

        $this->postJson(route('api.backoffice.produits.store'), [
            'nom' => 'Produit isolation API',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 1500,
            'fournisseur_id' => $fournisseurAilleurs->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fournisseur_id']);
    }

    public function test_store_validates_required_fields(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.backoffice.produits.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nom', 'type', 'statut']);
    }

    public function test_store_fails_sans_prix_requis_pour_le_type(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        // materiel exige prix_achat — appliqué via ProduitService::validerPrixSelonType(),
        // non contournable côté API (dette corrigée par la refonte).
        $this->postJson(route('api.backoffice.produits.store'), [
            'nom' => 'Sans prix',
            'type' => 'materiel',
            'statut' => 'actif',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('produits', ['nom' => 'Sans prix']);
    }

    // ── cohérence prix_vente / coût de référence (FABRICABLE, ACHAT_VENTE) ──────
    // Même règle que côté Web (ProduitService::validerPrixSelonType()) : l'API ne doit jamais
    // permettre de contourner ce que l'UI refuse (cf. spec "Prix produit & marge").

    public function test_store_fabricable_accepte_prix_vente_superieur_au_prix_usine(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.backoffice.produits.store'), [
            'nom' => 'Eau minérale 500ml',
            'type' => 'fabricable',
            'statut' => 'actif',
            'prix_usine' => 5100,
            'prix_vente' => 6000,
        ])->assertCreated();
    }

    public function test_store_fabricable_refuse_prix_vente_inferieur_ou_egal_au_prix_usine(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.backoffice.produits.store'), [
            'nom' => 'Pack bouteille 500ml',
            'type' => 'fabricable',
            'statut' => 'actif',
            'prix_usine' => 18000,
            'prix_vente' => 18000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prix_vente');

        $this->assertDatabaseMissing('produits', ['nom' => 'Pack bouteille 500ml']);
    }

    public function test_store_achat_vente_refuse_prix_vente_inferieur_ou_egal_au_prix_achat(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.backoffice.produits.store'), [
            'nom' => 'Produit revente à perte',
            'type' => 'achat_vente',
            'statut' => 'actif',
            'prix_achat' => 100000,
            'prix_vente' => 90000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prix_vente');

        $this->assertDatabaseMissing('produits', ['nom' => 'Produit revente à perte']);
    }

    public function test_update_fabricable_refuse_prix_vente_inferieur_ou_egal_au_prix_usine(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit fabricable',
            'type' => 'fabricable',
            'statut' => 'actif',
            'prix_usine' => 5100,
            'prix_vente' => 6000,
        ]);

        $this->putJson(route('api.backoffice.produits.update', $produit), [
            'prix_vente' => 5000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prix_vente');

        $this->assertSame(6000, $produit->fresh()->variantePrincipale()->first()->prix_vente);
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

        $this->assertDatabaseHas('produit_variantes', [
            'produit_id' => $produit->id,
            'prix_achat' => 800,
        ]);
    }

    public function test_update_sans_toucher_au_prix_ne_declenche_pas_derreur(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org);

        $this->putJson(route('api.backoffice.produits.update', $produit), [
            'nom' => 'Nom modifié',
        ])->assertOk();
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
            'motif_type' => 'apres_production',
        ])
            ->assertOk()
            ->assertJsonFragment(['qte_stock' => 70]);

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 70,
        ]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $this->varianteId($produit),
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
            'motif_type' => 'perte',
        ])
            ->assertOk()
            ->assertJsonFragment(['qte_stock' => 35]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $this->varianteId($produit),
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

    public function test_ajuster_stock_rejects_missing_motif(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org, ['qte_stock' => 10]);

        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'augmenter' => 5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motif_type');
    }

    public function test_ajuster_stock_rejects_motif_incompatible_avec_augmentation(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org, ['qte_stock' => 10]);

        // 'perte' is a sortie-only motif — incompatible with augmenter
        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'augmenter' => 5,
            'motif_type' => 'perte',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motif_type');
    }

    public function test_ajuster_stock_rejects_motif_incompatible_avec_diminution(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $produit = $this->makeProduit($this->org, ['qte_stock' => 10]);

        // 'apres_production' is an entree-only motif — incompatible with diminuer
        $this->postJson(route('api.backoffice.produits.ajuster-stock', $produit), [
            'diminuer' => 5,
            'motif_type' => 'apres_production',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motif_type');
    }
}
