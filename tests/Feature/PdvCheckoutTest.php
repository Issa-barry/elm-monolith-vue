<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
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

        // Stock ventilé par site (jamais d'agrégat legacy implicite) : le PDV vend
        // depuis le stock réel du site, cf. PdvCheckoutService::buildLignes().
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
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

    /**
     * Le fournisseur n'est pas une information nécessaire pour vendre un produit — le payload
     * PDV ne doit pas l'embarquer, même si le produit en a un.
     */
    public function test_pdv_index_nexpose_pas_le_fournisseur(): void
    {
        $fournisseur = Fournisseur::create([
            'organization_id' => $this->org->id,
            'raison_sociale' => 'SOGUIDEP',
            'phone' => '+224620000030',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'is_active' => true,
        ]);
        $this->produit->update(['fournisseur_id' => $fournisseur->id]);

        $this->actingAs($this->user)
            ->get('/backoffice/pdv')
            ->assertInertia(fn ($page) => $page
                ->component('PDV/Index')
                ->missing('produits.0.fournisseur_id')
                ->missing('produits.0.fournisseur_nom')
            );
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
        // setUp() a déjà créé la ligne VarianteStock de $this->site (100) — on l'ajuste
        // à la valeur voulue par ce scénario plutôt que d'en recréer une (contrainte
        // unique produit_variante_id + site_id).
        VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->site->id)
            ->update(['qte_stock' => 50]);
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
        ]);
        // Capacité portée exclusivement par le véhicule lui-même via vehicule_capacites
        // (décision produit du 17/08/2026, cf. VehiculeCapaciteService) — jamais par son type.
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Défaut']);
        $vehicule->capacites()->create([
            'organization_id' => $this->org->id,
            'categorie_id' => $categorie->id,
            'capacite_max' => 3,
        ]);
        $this->produit->update(['categorie_id' => $categorie->id]);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 10]],
            ])
            ->assertSessionHasErrors('lignes');
    }

    // ── Snapshots mode_tarification / commission_eligible ────────────────────
    // Un véhicule de flotte gérée facture toujours au prix de vente plein — seule
    // l'éligibilité aux commissions varie selon Vehicule::livraison_vente — voir
    // VehiculeCommandeContextResolver et CommandeVenteCommissionEligibiliteTest.
    #[DataProvider('livraisonVenteProvider')]
    public function test_checkout_mode_livreur_snapshot_reflete_le_vehicule(
        bool $livraisonVente,
    ): void {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
            'livraison_vente' => $livraisonVente,
        ]);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();
        $this->assertSame('prix_vente', $commande->mode_tarification_snapshot->value);
        $this->assertSame($livraisonVente, (bool) $commande->commission_eligible_snapshot);
    }

    public static function livraisonVenteProvider(): array
    {
        return [
            'livraison_vente activée' => [true],
            'livraison_vente désactivée' => [false],
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

    // ── Contrôle des impayés — même règle qu'au back-office, sur SolvabiliteService ─
    // (trou identifié le 18/08/2026 : le PDV créait auparavant sa facture sans AUCUN contrôle,
    // quel que soit le paramétrage de l'organisation — cf. PdvCheckoutService::checkout()).

    /** Facture IMPAYEE rattachée à un véhicule, via sa commande — même helper que CommandeVenteTest. */
    private function makeDetteVehicule(int $montant, string $vehiculeId): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehiculeId,
        ]);

        return FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehiculeId,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => StatutFactureVente::IMPAYEE->value,
        ]);
    }

    /**
     * Dette PARTIELLEMENT réglée — isole le contrôle de SEUIL du verrou « première
     * régularisation » (cf. SolvabiliteService), qui bloquerait sinon inconditionnellement
     * n'importe quelle facture à 0 encaissé, quel que soit le seuil ou la dérogation.
     */
    private function makeDetteVehiculePartielle(int $montant, int $encaisse, string $vehiculeId): FactureVente
    {
        $facture = $this->makeDetteVehicule($montant, $vehiculeId);
        $facture->update(['statut_facture' => StatutFactureVente::PARTIEL->value]);

        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => $encaisse,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        return $facture;
    }

    public function test_checkout_mode_livreur_bloque_quand_dette_vehicule_depasse_le_seuil(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $this->makeDetteVehicule(150_000, $vehicule->id);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionHasErrors('impayes');

        // makeDetteVehicule() a déjà créé une commande pour ce véhicule : seule la tentative
        // PDV a échoué, aucune commande supplémentaire.
        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_checkout_mode_livreur_autorise_sous_le_seuil(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $this->makeDetteVehiculePartielle(50_000, 10_000, $vehicule->id);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');

        $this->assertSame(2, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    /** La dérogation via le type de véhicule s'applique aussi côté PDV — même service. */
    public function test_checkout_mode_livreur_autorise_grace_a_la_derogation_du_type_de_vehicule(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'seuil_derogation_impayes' => 2_000_000]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule_id' => $type->id,
            'derogation_impayes_autorisee' => true,
        ]);
        $this->makeDetteVehiculePartielle(1_500_000, 10_000, $vehicule->id);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }

    // ── Verrou « première régularisation » côté PDV — même service que le back-office ───────
    // (couverture détaillée du calcul dans tests/Unit/SolvabiliteServiceTest, du parcours
    // back-office dans VehiculePremiereRegularisationTest ; ici on vérifie juste que le PDV,
    // deuxième appelant de SolvabiliteService, applique bien le même verrou.)

    public function test_checkout_mode_livreur_bloque_quand_facture_precedente_non_encaissee_meme_sous_le_seuil(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000_000); // seuil énorme, ne doit pas suffire
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $this->makeDetteVehicule(10_000, $vehicule->id); // 0 encaissé

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionHasErrors('impayes');

        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_checkout_mode_livreur_bloque_par_le_verrou_meme_controle_impayes_desactive(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $this->makeDetteVehicule(10_000, $vehicule->id);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionHasErrors('impayes');
    }

    public function test_checkout_mode_client_bloque_quand_dette_client_depasse_le_seuil(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'client_id' => $client->id,
        ]);
        FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 150_000,
            'montant_net' => 150_000,
            'statut_facture' => StatutFactureVente::IMPAYEE->value,
        ]);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Client',
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionHasErrors('impayes');
    }

    /**
     * Mode "Vente rapide" : ni véhicule ni client, donc rien à contrôler — ne doit jamais être
     * bloqué par le contrôle des impayés, même avec le contrôle actif par défaut.
     */
    public function test_checkout_vente_rapide_nest_jamais_bloquee_par_le_controle_impayes(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Vente rapide',
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }
}
