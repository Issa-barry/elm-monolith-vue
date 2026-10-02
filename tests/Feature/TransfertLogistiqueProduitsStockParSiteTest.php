<?php

namespace Tests\Feature;

use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Chantier UX du 04/09/2026 : le formulaire de transfert logistique (Logistique/Create.vue)
 * n'affichait ni ne plafonnait le stock disponible du site source avant soumission — l'erreur
 * n'apparaissait qu'après coup (cf. TransfertLogistiqueCreationStockInsuffisantTest, qui couvre
 * déjà ce contrôle backend strict). Ce test couvre l'enrichissement des props `produits` de
 * create()/edit() (TransfertLogistiqueController::produitsAvecStock()) : chaque produit porte
 * désormais `gere_stock` et `stocks_par_site` (disponible = qte_stock − qte_reservee, PAR site),
 * pour permettre au frontend de désactiver un produit en rupture et de plafonner la quantité,
 * SANS jamais dupliquer le calcul serveur (TransfertLogistiqueService::verifierDisponibiliteLignes()
 * reste la seule protection réelle).
 *
 * Couvre aussi l'éligibilité au sélecteur (04/09/2026) : `produitsAvecStock()` ne proposait
 * auparavant AUCUN filtre (tout produit de l'organisation, y compris archivé ou de type
 * `service`). Filtre désormais sur ACTIF + produitType.code === 'fabricable' uniquement — un
 * transfert logistique déplace le produit fini entre sites, le matériel/la matière de
 * production/les produits Achat-Vente ne transitent pas par ce circuit.
 */
class TransfertLogistiqueProduitsStockParSiteTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $userAdmin;

    private Site $siteSource;

    private Site $siteDestination;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.update', 'guard_name' => 'web']);

        $this->userAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->userAdmin->assignRole('admin_entreprise');
        $this->userAdmin->givePermissionTo(['logistique.create', 'logistique.update']);

        $this->siteSource = Site::create([
            'organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Conakry',
        ]);
        $this->siteDestination = Site::create([
            'organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot', 'localisation' => 'Conakry',
        ]);
        // RequireSiteAssigned n'exempte que super_admin (jamais admin_entreprise) : un admin non
        // affecté à un site reçoit un 403 avant même d'atteindre le contrôleur.
        $this->userAdmin->sites()->attach($this->siteSource->id, ['role' => 'employe', 'is_default' => true]);

        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);
    }

    private function produitsProp(): array
    {
        return $this->actingAs($this->userAdmin)
            ->get('/backoffice/logistique/creer')
            ->assertOk()
            ->viewData('page')['props']['produits'];
    }

    private function trouverProduit(array $produits, string $nom): ?array
    {
        foreach ($produits as $p) {
            if ($p['nom'] === $nom) {
                return $p;
            }
        }

        return null;
    }

    public function test_stock_disponible_du_site_source_est_expose_par_produit(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteille 1500ml', 'type' => 'fabricable']);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->siteSource->id,
            'qte_stock' => 800,
            'qte_reservee' => 0,
        ]);

        $ligne = $this->trouverProduit($this->produitsProp(), 'Pack Bouteille 1500ml');

        $this->assertNotNull($ligne);
        $this->assertTrue($ligne['gere_stock']);
        $this->assertSame(800, $ligne['stocks_par_site'][(string) $this->siteSource->id]);
    }

    public function test_reservation_est_deduite_du_stock_physique(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Réservé', 'type' => 'fabricable']);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->siteSource->id,
            'qte_stock' => 100,
            'qte_reservee' => 30,
        ]);

        $ligne = $this->trouverProduit($this->produitsProp(), 'Pack Réservé');

        $this->assertSame(70, $ligne['stocks_par_site'][(string) $this->siteSource->id]);
    }

    public function test_produit_sans_ligne_de_stock_sur_le_site_napparait_pas_dans_stocks_par_site(): void
    {
        $this->makeProduitAvecVariante($this->org, ['nom' => 'Jamais Stocké', 'type' => 'fabricable']);

        $ligne = $this->trouverProduit($this->produitsProp(), 'Jamais Stocké');

        $this->assertNotNull($ligne);
        $this->assertTrue($ligne['gere_stock']);
        $this->assertArrayNotHasKey((string) $this->siteSource->id, $ligne['stocks_par_site']);
    }

    public function test_le_stock_du_site_destination_najamais_pris_en_compte_pour_le_site_source(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Multi-Site', 'type' => 'fabricable']);
        $variante = $produit->variantePrincipale()->first();
        // Stock confortable à destination, nul à la source : le site source doit rester
        // indisponible, jamais "débloqué" par le stock d'un autre site.
        VarianteStock::create([
            'organization_id' => $this->org->id, 'produit_variante_id' => $variante->id,
            'site_id' => $this->siteDestination->id, 'qte_stock' => 1000,
        ]);
        VarianteStock::create([
            'organization_id' => $this->org->id, 'produit_variante_id' => $variante->id,
            'site_id' => $this->siteSource->id, 'qte_stock' => 0,
        ]);

        $ligne = $this->trouverProduit($this->produitsProp(), 'Pack Multi-Site');

        $this->assertSame(0, $ligne['stocks_par_site'][(string) $this->siteSource->id]);
        $this->assertSame(1000, $ligne['stocks_par_site'][(string) $this->siteDestination->id]);
    }

    public function test_produit_de_type_service_nest_pas_propose_au_transfert(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Prestation Livraison', 'type' => 'service']);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->siteSource->id,
            'qte_stock' => 0,
        ]);

        $ligne = $this->trouverProduit($this->produitsProp(), 'Prestation Livraison');

        $this->assertNull($ligne);
    }

    public function test_produit_inactif_nest_pas_propose_au_transfert(): void
    {
        $this->makeProduitAvecVariante($this->org, [
            'nom' => 'Ancien Pack',
            'type' => 'fabricable',
            'statut' => 'archive',
        ]);

        $ligne = $this->trouverProduit($this->produitsProp(), 'Ancien Pack');

        $this->assertNull($ligne);
    }

    /**
     * Règle confirmée le 04/09/2026 (revient sur un élargissement à tout produitType.gere_stock=true
     * tenté plus tôt le même jour) : seul le type `fabricable` est éligible au transfert
     * logistique — matériel, matière de production et Achat/Vente sont gérés en stock mais ne
     * transitent pas par ce circuit (dépôt → point de vente), même s'ils ne sont pas vendables
     * en Ventes (`vendable=false`).
     */
    public function test_produit_materiel_matiere_production_et_achat_vente_ne_sont_pas_proposes_au_transfert(): void
    {
        foreach (['materiel', 'matiere_production', 'achat_vente'] as $type) {
            $produit = $this->makeProduitAvecVariante($this->org, ['nom' => "Produit {$type}", 'type' => $type]);
            VarianteStock::create([
                'organization_id' => $this->org->id,
                'produit_variante_id' => $produit->variantePrincipale()->first()->id,
                'site_id' => $this->siteSource->id,
                'qte_stock' => 50,
            ]);
        }

        $produits = $this->produitsProp();

        foreach (['materiel', 'matiere_production', 'achat_vente'] as $type) {
            $this->assertNull($this->trouverProduit($produits, "Produit {$type}"), "Le type {$type} ne doit pas être proposé au transfert.");
        }
    }

    public function test_isolation_entre_organisations(): void
    {
        $orgB = Organization::factory()->create();
        $produitB = $this->makeProduitAvecVariante($orgB, ['nom' => 'Produit Org B', 'type' => 'fabricable']);
        VarianteStock::create([
            'organization_id' => $orgB->id,
            'produit_variante_id' => $produitB->variantePrincipale()->first()->id,
            'site_id' => $this->siteSource->id,
            'qte_stock' => 500,
        ]);

        $ligne = $this->trouverProduit($this->produitsProp(), 'Produit Org B');

        $this->assertNull($ligne);
    }

    public function test_edit_expose_aussi_les_stocks_par_site(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Edition', 'type' => 'fabricable']);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->siteSource->id,
            'qte_stock' => 12,
        ]);

        $vehicule = Vehicule::where('organization_id', $this->org->id)->first();
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->siteSource->id,
            'site_destination_id' => $this->siteDestination->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutTransfert::BROUILLON,
            'created_by' => $this->userAdmin->id,
        ]);
        $transfert->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 1,
        ]);

        $this->actingAs($this->userAdmin)
            ->get("/backoffice/logistique/{$transfert->id}/editer")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistique/Create')
                ->where('produits', fn ($produits) => collect($produits)
                    ->firstWhere('nom', 'Pack Edition')['stocks_par_site'][(string) $this->siteSource->id] === 12)
            );
    }
}
