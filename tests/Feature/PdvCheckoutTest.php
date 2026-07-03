<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class PdvCheckoutTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private User $user;

    private Organization $org;

    private Site $site;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create', 'ventes.update']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site PDV',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Pack 30',
            'type' => 'fabricable',
            'statut' => 'actif',
            'prix_vente' => 5000,
            'prix_usine' => 3000,
            'qte_stock' => 100,
        ]);
    }

    // ── GET /pdv ──────────────────────────────────────────────────────────────

    public function test_pdv_index_renders_with_produits(): void
    {
        $this->actingAs($this->user)
            ->get('/backoffice/pdv')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('PDV/Index')
                ->has('produits')
                ->has('vehicules')
                ->has('clients')
            );
    }

    public function test_pdv_index_redirects_unauthenticated(): void
    {
        $this->get('/backoffice/pdv')->assertRedirect(route('login'));
    }

    // ── POST /pdv/checkout — Vente rapide ─────────────────────────────────────

    public function test_checkout_vente_rapide_creates_commande_en_cours(): void
    {
        $response = $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 2]],
        ]);

        $response->assertRedirect();

        $commande = CommandeVente::first();
        $this->assertNotNull($commande);
        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        $this->assertEquals(10000, $commande->total_commande);
        $this->assertNull($commande->vehicule_id);
        $this->assertNull($commande->client_id);
    }

    public function test_checkout_decremente_le_stock(): void
    {
        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 5]],
        ]);

        $this->assertEquals(95, $this->produit->fresh()->qte_stock);
    }

    public function test_checkout_cree_une_facture(): void
    {
        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
        ]);

        $commande = CommandeVente::first();
        $this->assertNotNull($commande->facture);
        $this->assertEquals(5000, $commande->facture->montant_net);
    }

    // ── POST /pdv/checkout — Mode Client ─────────────────────────────────────

    public function test_checkout_mode_client_requires_client_id(): void
    {
        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Client',
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionHasErrors('client_id');
    }

    public function test_checkout_mode_client_with_client_succeeds(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Client',
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertRedirect();

        $commande = CommandeVente::first();
        $this->assertEquals($client->id, $commande->client_id);
    }

    // ── POST /pdv/checkout — Mode Livreur ────────────────────────────────────

    public function test_checkout_mode_livreur_requires_vehicule_id(): void
    {
        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionHasErrors('vehicule_id');
    }

    public function test_checkout_mode_livreur_capacite_depassee(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 3,
        ]);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 10]],
            ])
            ->assertSessionHasErrors('lignes');
    }

    // ── Validation stock ──────────────────────────────────────────────────────

    public function test_checkout_refuse_si_stock_insuffisant(): void
    {
        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Vente rapide',
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 999]],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertEquals(100, $this->produit->fresh()->qte_stock);
    }

    public function test_checkout_refuse_panier_vide(): void
    {
        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Vente rapide',
                'lignes' => [],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_checkout_redirects_unauthenticated(): void
    {
        $this->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
        ])->assertRedirect(route('login'));
    }
}
