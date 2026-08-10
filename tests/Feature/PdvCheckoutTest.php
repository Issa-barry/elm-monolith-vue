<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class PdvCheckoutTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

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

        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 30', 'type' => 'fabricable', 'qte_stock' => 100],
            ['prix_vente' => 5000, 'prix_usine' => 3000],
        );
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

    /**
     * Le PDV recherche un article par référence OU par code-barres (scan) — les deux
     * doivent être transmis au frontend, pas seulement la référence.
     */
    public function test_pdv_index_expose_le_code_barres_pour_le_scan(): void
    {
        $this->produit->variantePrincipale()->first()->update(['code_barres' => '3274080005003']);

        $this->actingAs($this->user)
            ->get('/backoffice/pdv')
            ->assertInertia(fn ($page) => $page
                ->component('PDV/Index')
                ->where('produits.0.codeBarres', '3274080005003')
            );
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

    public function test_checkout_ne_decremente_pas_le_stock_dun_autre_site(): void
    {
        // Répartition explicite par site — reproduit le bug corrigé où le
        // PDV décrémentait l'agrégat global au lieu du stock du site vendeur.
        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre site',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $variante = $this->produit->variantePrincipale()->first();
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $variante->id,
            'site_id' => $this->site->id,
            'qte_stock' => 50,
        ]);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $variante->id,
            'site_id' => $autreSite->id,
            'qte_stock' => 30,
        ]);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 5]],
        ])->assertRedirect();

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $variante->id,
            'site_id' => $this->site->id,
            'qte_stock' => 45,
        ]);
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $variante->id,
            'site_id' => $autreSite->id,
            'qte_stock' => 30,
        ]);
        $this->assertEquals(75, $this->produit->fresh()->qte_stock);
    }

    public function test_checkout_trace_un_mouvement_de_stock(): void
    {
        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 3]],
        ])->assertRedirect();

        $variante = $this->produit->variantePrincipale()->first();
        $ligne = CommandeVenteLigne::where('variante_id', $variante->id)->firstOrFail();

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $variante->id,
            'site_id' => $this->site->id,
            'type' => 'sortie',
            'quantite' => 3,
            'stock_avant' => 100,
            'stock_apres' => 97,
            'source_type' => CommandeVenteLigne::class,
            'source_id' => $ligne->id,
        ]);
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

    // ── Snapshots mode_tarification / commission_eligible ────────────────────
    // Les deux notions sont indépendantes — voir VehiculeCommandeContextResolver.
    // Testées ici via le même chemin HTTP que le reste de la classe, sur 3 des
    // 4 combinaisons possibles (la 4e — deux "non" — est couverte côté
    // CommandeVenteCommissionEligibiliteTest) pour prouver que le mode de
    // tarification et l'éligibilité aux commissions ne sont jamais dérivés
    // l'un de l'autre, y compris sur le chemin PDV.
    #[DataProvider('prisEnChargeEtCommissionEligibleProvider')]
    public function test_checkout_mode_livreur_snapshot_reflete_le_vehicule(
        bool $prisEnChargeParUsine,
        bool $commissionEligible,
        string $modeTarificationAttendu,
    ): void {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
            'pris_en_charge_par_usine' => $prisEnChargeParUsine,
            'commission_eligible' => $commissionEligible,
        ]);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();
        $this->assertSame($modeTarificationAttendu, $commande->mode_tarification_snapshot->value);
        $this->assertSame($commissionEligible, (bool) $commande->commission_eligible_snapshot);
    }

    public static function prisEnChargeEtCommissionEligibleProvider(): array
    {
        return [
            'pris en charge + éligible' => [true, true, 'prix_vente'],
            'pris en charge + non éligible' => [true, false, 'prix_vente'],
            'non pris en charge + éligible' => [false, true, 'prix_usine'],
        ];
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
