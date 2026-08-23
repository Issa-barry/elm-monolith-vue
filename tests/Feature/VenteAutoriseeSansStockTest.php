<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\CommandeVenteService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Politique globale d'organisation (Paramètres > Paramètres produits, DSI 23/08/2026) :
 * Parametre::isVentesAutoriseesSansStock() décide si le PDV et les commandes vente peuvent
 * continuer quand le stock disponible est insuffisant ou nul — jamais un réglage par produit
 * (rejeté explicitement le 23/08/2026 : "tous les produits vendables de l'organisation suivent
 * la même politique"). Jamais appliqué aux transferts ni aux ajustements manuels, qui restent
 * toujours stricts quel que soit ce paramètre.
 */
class VenteAutoriseeSansStockTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        // Pas HasOrgAndUser::initOrgAndUser() ici : cette méthode crée déjà un site par défaut
        // en interne — en créer un second (même pattern que d'autres tests CommandeVente, sans
        // risque là où le site est toujours référencé explicitement via commande->site_id) créait
        // ici DEUX sites marqués is_default=true sur le même utilisateur, et PdvCheckoutService
        // résolvait alors le stock sur l'autre site que celui vérifié par ce test. Même
        // construction minimaliste, à un seul site, que PdvCheckoutTest.
        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create', 'ventes.update', 'produits.read']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Eau'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    private function seedStock(int $qte): VarianteStock
    {
        return VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->produit->variantePrincipale()->first()->id, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    private function assertStock(int $qteAttendue): void
    {
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => $qteAttendue,
        ]);
    }

    // ── PDV — politique OFF (défaut) ─────────────────────────────────────────

    public function test_pdv_autorise_vente_egale_au_stock_disponible_meme_politique_off(): void
    {
        $this->seedStock(100);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 100]],
        ])->assertRedirect();

        $this->assertStock(0);
    }

    public function test_pdv_refuse_vente_superieure_au_stock_quand_politique_off(): void
    {
        $this->seedStock(100);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 101]],
        ])->assertSessionHasErrors('lignes');

        $this->assertStock(100);
    }

    public function test_pdv_refuse_vente_sur_stock_zero_quand_politique_off(): void
    {
        $this->seedStock(0);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
        ])->assertSessionHasErrors('lignes');
    }

    // ── PDV — politique ON ────────────────────────────────────────────────────

    public function test_pdv_autorise_vente_superieure_au_stock_quand_politique_on(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(100);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 101]],
        ])->assertRedirect();

        $this->assertStock(-1);
    }

    public function test_pdv_autorise_vente_sur_stock_zero_quand_politique_on(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(0);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 50]],
        ])->assertRedirect();

        $this->assertStock(-50);
    }

    public function test_pdv_autorise_vente_a_partir_dun_stock_deja_negatif(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(-50);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 25]],
        ])->assertRedirect();

        $this->assertStock(-75);
    }

    // ── Isolation multi-organisation ─────────────────────────────────────────

    public function test_politique_est_isolee_par_organisation(): void
    {
        $orgB = Organization::factory()->create();
        $userB = $this->makeUserWithPermissions($orgB, ['ventes.read', 'ventes.create', 'ventes.update']);
        $siteB = Site::create([
            'organization_id' => $orgB->id,
            'nom' => 'Site B',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $userB->sites()->attach($siteB->id, ['role' => 'employe', 'is_default' => true]);
        $produitB = $this->makeProduitAvecVariante(
            $orgB,
            ['nom' => 'Pack Eau B'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        VarianteStock::create([
            'organization_id' => $orgB->id,
            'produit_variante_id' => $produitB->variantePrincipale()->first()->id,
            'site_id' => $siteB->id,
            'qte_stock' => 10,
        ]);

        // Org A active la politique ; org B reste sur le défaut (false), jamais configurée.
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(10);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 15]],
        ])->assertRedirect();
        $this->assertStock(-5);

        $this->actingAs($userB)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $produitB->id, 'quantite' => 15]],
        ])->assertSessionHasErrors('lignes');

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $produitB->variantePrincipale()->first()->id,
            'site_id' => $siteB->id,
            'qte_stock' => 10,
        ]);
    }

    // ── Commande vente — chargement ──────────────────────────────────────────

    /** @return array{0: CommandeVente, 1: \App\Models\CommandeVenteLigne} */
    private function makeCommandeEnChargement(int $quantiteDemandee): array
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 0,
        ]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $quantiteDemandee,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => $quantiteDemandee * 1500.0,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);

        return [$commande, $ligne];
    }

    public function test_commande_vente_refuse_le_chargement_si_stock_insuffisant_et_politique_off(): void
    {
        $this->seedStock(100);
        [$commande, $ligne] = $this->makeCommandeEnChargement(101);

        try {
            CommandeVenteService::validerChargement($commande, [[
                'id' => $ligne->id,
                'quantite_chargee' => 101,
                'type_ecart' => 'surplus',
            ]]);
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu
        }

        $this->assertStock(100);
        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
    }

    public function test_commande_vente_autorise_le_chargement_au_dela_du_stock_si_politique_on(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(100);
        [$commande, $ligne] = $this->makeCommandeEnChargement(150);

        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => 150,
            'type_ecart' => 'surplus',
        ]]);

        $this->assertStock(-50);
        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
    }

    // ── Transferts — toujours stricts, même politique ON ─────────────────────

    public function test_transfert_reste_refuse_meme_avec_politique_vente_activee(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(100);

        $siteDestination = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Destination',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDestination->id,
            'statut' => StatutTransfert::CHARGEMENT,
            'created_by' => $this->user->id,
        ]);
        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 150,
            'quantite_chargee' => 150,
        ]);

        try {
            TransfertLogistiqueService::avancerStatut($transfert);
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu : la politique de vente ne s'applique jamais aux transferts.
        }

        $this->assertStock(100);
        $this->assertEquals(StatutTransfert::CHARGEMENT, $transfert->fresh()->statut);
    }

    // ── Ajustement manuel — toujours strict, même politique ON ───────────────

    public function test_ajustement_manuel_reste_refuse_meme_avec_politique_vente_activee(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(100);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'diminuer' => 150,
                'motif_type' => 'perte',
            ])
            ->assertSessionHasErrors();

        $this->assertStock(100);
    }
}
