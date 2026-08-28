<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Tarification par nature de client (Externe/Revendeur/Distributeur) sur un produit
 * fabricable — bout-en-bout via CommandeVenteController::store() (back-office) et
 * PdvCheckoutService::checkout() (PDV). Cf. rapport du 28/08/2026 : remplace prix_vente
 * uniquement pour les produits fabricables, jamais pour les autres types (comportement
 * historique inchangé, cf. CommandeVenteModeTarificationTest).
 */
class CommandeVenteNaturePricingTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private User $user;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create', 'ventes.update']);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeFabricable(array $varianteOverrides = []): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500ml', 'type' => 'fabricable'],
            array_merge([
                'prix_vente' => 22000,
                'prix_usine' => 18000,
                'prix_externe' => 18250,
                'prix_revendeur' => 20000,
                'prix_distributeur' => 18500,
            ], $varianteOverrides),
        );
    }

    // ── Back-office (CommandeVenteController::store()) ───────────────────────────

    public function test_store_facture_un_client_revendeur_au_prix_revendeur(): void
    {
        $produit = $this->makeFabricable();
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'revendeur']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 3, 'prix_vente' => 22000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(60000.0, (float) $commande->total_commande); // 3 × 20000
        $this->assertEquals(20000.0, (float) $commande->lignes->first()->prix_vente_snapshot);
        $this->assertSame('revendeur', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_store_facture_un_client_distributeur_au_prix_distributeur(): void
    {
        $produit = $this->makeFabricable();
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 22000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(37000.0, (float) $commande->total_commande); // 2 × 18500
        $this->assertSame('distributeur', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_store_sans_tarif_distributeur_configure_retombe_sur_prix_vente(): void
    {
        $produit = $this->makeFabricable(['prix_distributeur' => null]);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 22000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(22000.0, (float) $commande->total_commande);
        $this->assertSame('vente', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_store_produit_non_fabricable_ignore_la_nature_du_client(): void
    {
        // Type par défaut du trait = 'materiel' (non-fabricable) : comportement historique.
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Bidon'],
            ['prix_vente' => 10000, 'prix_achat' => 6000],
        );
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'revendeur']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 10000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(20000.0, (float) $commande->total_commande);
        $this->assertSame('vente', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    // ── PDV (PdvCheckoutService::checkout()) ─────────────────────────────────────

    public function test_pdv_checkout_facture_un_client_revendeur_au_prix_revendeur(): void
    {
        $produit = $this->makeFabricable();
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 100,
        ]);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'revendeur']);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 4]],
        ])->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(80000.0, (float) $commande->total_commande); // 4 × 20000
        $this->assertSame('revendeur', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_pdv_checkout_ignore_le_prix_envoye_par_le_frontend(): void
    {
        // Le PDV ne reçoit jamais de prix du frontend (contrairement au back-office) — même en
        // forçant une valeur dans la requête, le serveur reste seul juge (cf. PrixVenteNatureResolver).
        $produit = $this->makeFabricable();
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 100,
        ]);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'prix_vente' => 1]],
        ])->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(18250.0, (float) $commande->total_commande);
        $this->assertSame('externe', $commande->lignes->first()->prix_origine_snapshot->value);
    }
}
