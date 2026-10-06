<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Correctif du 30/08/2026 : CommandeVenteService::creerFactureDirecte() (vente directe sans
 * véhicule) émettait une facture IMPAYEE sans jamais toucher au stock — ni réservation ni
 * sortie physique, contrairement au chemin véhicule (reserverLignes() puis decrementerStock()).
 * Une vente directe pouvait donc vider un stock déjà vendu ailleurs sans que le système ne s'en
 * aperçoive. decrementerStockDirect() comble ce trou en réutilisant la même primitive
 * (MouvementStockService::appliquer(), via sortirStock()) que le reste de l'application — même
 * garde-fou anti-survente, même politique Parametre::isVentesAutoriseesSansStock().
 */
class CommandeVenteDirecteStockTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create', 'ventes.update']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille de 1500ml', 'type' => 'fabricable'],
            ['prix_vente' => 20000, 'prix_usine' => 15000],
        );
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);
    }

    private function seedStock(int $qte): VarianteStock
    {
        return VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->produit->variantePrincipale()->first()->id, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    private function creerCommandeDirecteBrouillon(int $qte): CommandeVente
    {
        $variante = $this->produit->variantePrincipale()->first();
        $totalLigne = $qte * 20000.0;

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $this->client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => $totalLigne,
        ]);
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => 15000.0,
            'prix_vente_snapshot' => 20000.0,
            'total_ligne' => $totalLigne,
        ]);

        return $commande;
    }

    public function test_vente_directe_decremente_le_stock_physique_via_le_flux_http(): void
    {
        $this->seedStock(460);
        $varianteId = $this->produit->variantePrincipale()->first()->id;

        $this->actingAs($this->user)->post('/backoffice/ventes', [
            'client_id' => $this->client->id,
            'lignes' => [
                ['produit_id' => $this->produit->id, 'qte' => 50, 'prix_vente' => 20000],
            ],
        ])->assertRedirect();

        $commande = CommandeVente::where('client_id', $this->client->id)->firstOrFail();
        $this->assertEquals(StatutCommandeVente::FACTURATION, $commande->statut);

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $this->site->id,
            'qte_stock' => 410,
        ]);

        $ligne = $commande->lignes()->first();
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $varianteId,
            'site_id' => $this->site->id,
            'type' => 'sortie',
            'quantite' => 50,
            'source_type' => CommandeVenteLigne::class,
            'source_id' => $ligne->id,
        ]);
    }

    public function test_creer_facture_directe_decremente_le_stock_pour_chaque_ligne_gerant_du_stock(): void
    {
        $this->seedStock(200);
        $commande = $this->creerCommandeDirecteBrouillon(30);

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 170,
        ]);
        $this->assertNotNull($commande->fresh()->facture);
    }

    public function test_creer_facture_directe_ignore_les_produits_ne_gerant_pas_de_stock(): void
    {
        $service = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Prestation de service', 'type' => 'service'],
            ['prix_vente' => 10000, 'prix_usine' => 0],
        );
        $varianteService = $service->variantePrincipale()->first();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $this->client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 10000.0,
        ]);
        $commande->lignes()->create([
            'variante_id' => $varianteService->id,
            'quantite_demandee' => 5,
            'prix_usine_snapshot' => 0.0,
            'prix_vente_snapshot' => 10000.0,
            'total_ligne' => 50000.0,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->assertDatabaseMissing('mouvements_stock', [
            'produit_variante_id' => $varianteService->id,
        ]);
    }

    public function test_creer_facture_directe_refuse_si_stock_insuffisant_sans_politique_permissive(): void
    {
        $this->seedStock(10);
        $commande = $this->creerCommandeDirecteBrouillon(50);

        $this->actingAs($this->user);

        try {
            CommandeVenteService::creerFactureDirecte($commande);
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu : même garde-fou anti-survente que le chemin véhicule.
        }

        // Rollback complet de la transaction : ni statut, ni stock, ni facture.
        $this->assertEquals(StatutCommandeVente::BROUILLON, $commande->fresh()->statut);
        $this->assertNull($commande->fresh()->facture);
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 10,
        ]);
    }

    public function test_creer_facture_directe_autorise_le_stock_negatif_quand_la_politique_est_activee(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(10);
        $commande = $this->creerCommandeDirecteBrouillon(50);

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => -40,
        ]);
    }

    public function test_annulation_dune_vente_directe_reintegre_le_stock_physique(): void
    {
        $this->seedStock(200);
        $commande = $this->creerCommandeDirecteBrouillon(30);
        $varianteId = $this->produit->variantePrincipale()->first()->id;

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $this->site->id,
            'qte_stock' => 170,
        ]);

        CommandeVenteService::annuler($commande->fresh(), 'Erreur de saisie');

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $this->site->id,
            'qte_stock' => 200,
        ]);

        $sortie = MouvementStock::where('type', 'sortie')
            ->where('produit_variante_id', $varianteId)
            ->firstOrFail();
        $this->assertNotNull($sortie->annule_par_id);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $varianteId,
            'type' => 'entree',
            'quantite' => 30,
            'id' => $sortie->annule_par_id,
        ]);
    }

    public function test_annulation_dune_vente_directe_sans_stock_gere_reste_un_noop_sans_erreur(): void
    {
        $service = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Prestation', 'type' => 'service'],
            ['prix_vente' => 10000, 'prix_usine' => 0],
        );
        $varianteService = $service->variantePrincipale()->first();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $this->client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 10000.0,
        ]);
        $commande->lignes()->create([
            'variante_id' => $varianteService->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 0.0,
            'prix_vente_snapshot' => 10000.0,
            'total_ligne' => 20000.0,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        CommandeVenteService::annuler($commande->fresh(), 'Test');

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }
}
