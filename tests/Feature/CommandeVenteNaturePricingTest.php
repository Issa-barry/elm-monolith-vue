<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\VarianteStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
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
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
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

    /**
     * Factorise le triplet post ventes.store() → assertRedirect() → fetch de la commande créée,
     * commun aux tests back-office ci-dessous (déclencheur de duplication SonarCloud avec
     * CommandeVenteGrossisteModeEtFallbackTest, même pattern côté back-office).
     */
    private function posterVenteEtRecupererCommande(string $clientId, int $qte, string $produitId, float $prixVente = 22000): CommandeVente
    {
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $clientId,
                'lignes' => [
                    ['produit_id' => $produitId, 'qte' => $qte, 'prix_vente' => $prixVente],
                ],
            ])
            ->assertRedirect();

        return CommandeVente::where('client_id', $clientId)->latest()->firstOrFail();
    }

    // ── Back-office (CommandeVenteController::store()) ───────────────────────────

    public function test_store_facture_un_client_revendeur_au_prix_revendeur(): void
    {
        $produit = $this->makeFabricable();
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'revendeur']);

        $commande = $this->posterVenteEtRecupererCommande($client->id, 3, $produit->id);
        $this->assertEquals(60000.0, (float) $commande->total_commande); // 3 × 20000
        $this->assertEquals(20000.0, (float) $commande->lignes->first()->prix_vente_snapshot);
        $this->assertSame('revendeur', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_store_facture_un_client_distributeur_au_prix_distributeur(): void
    {
        $produit = $this->makeFabricable();
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);

        $commande = $this->posterVenteEtRecupererCommande($client->id, 2, $produit->id);
        $this->assertEquals(37000.0, (float) $commande->total_commande); // 2 × 18500
        $this->assertSame('distributeur', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_store_sans_tarif_distributeur_configure_retombe_sur_prix_vente(): void
    {
        $produit = $this->makeFabricable(['prix_distributeur' => null]);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);

        $commande = $this->posterVenteEtRecupererCommande($client->id, 1, $produit->id);
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

        $commande = $this->posterVenteEtRecupererCommande($client->id, 2, $produit->id, prixVente: 10000);
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
