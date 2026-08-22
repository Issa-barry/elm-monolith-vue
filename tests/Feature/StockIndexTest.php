<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\DroitAjustementStock;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

class StockIndexTest extends TestCase
{
    use HasProduitVariante;
    use RefreshDatabase;

    private Organization $organization;

    private Site $siteA;

    private Site $siteB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->organization->id);
        $this->siteA = Site::factory()->create([
            'organization_id' => $this->organization->id,
            'nom' => 'Agence Alpha',
        ]);
        $this->siteB = Site::factory()->create([
            'organization_id' => $this->organization->id,
            'nom' => 'Agence Beta',
        ]);

        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->admin->assignRole('admin_entreprise');
        $this->admin->givePermissionTo('produits.read');
        $this->admin->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);
    }

    public function test_la_page_exige_la_permission_produits_read(): void
    {
        $user = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($user)
            ->get(route('produits.stock.index'))
            ->assertForbidden();
    }

    public function test_la_page_affiche_le_grain_variante_site_et_exclut_les_types_sans_stock(): void
    {
        $produit = $this->makeProduitAvecVariante($this->organization, [
            'nom' => 'Bidon premium',
            'alerte_stock_active' => true,
            'seuil_alerte_stock' => 5,
        ], ['sku' => 'BIDON-001']);
        $variante = $produit->variantePrincipale()->first();
        $secondeVariante = $this->makeVariante($produit, [
            'sku' => 'BIDON-002',
            'is_default' => false,
            'combo_hash' => 'bidon-deux',
            'position' => 2,
        ]);
        $this->stock($variante, $this->siteA, 3);
        $this->stock($secondeVariante, $this->siteA, 12);

        $this->makeProduitAvecVariante($this->organization, [
            'nom' => 'Service livraison',
            'produit_type_id' => ProduitType::where('organization_id', $this->organization->id)
                ->where('code', 'service')
                ->value('id'),
        ], ['sku' => 'SERVICE-001']);

        $this->actingAs($this->admin)
            ->get(route('produits.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Produits/Stock/Index')
                ->has('stocks.data', 4)
                ->where('stocks.data.0.produit_nom', 'Bidon premium')
                ->where('stocks.data.0.site_nom', 'Agence Alpha')
                ->where('stocks.data.0.qte_stock', 3)
                ->where('stocks.data.0.statut', 'stock_faible')
                ->where('stocks.data.0.seuil_effectif', 5)
                ->where('stocks.data.1.site_nom', 'Agence Beta')
                ->where('stocks.data.1.qte_stock', 0)
                ->where('stocks.data.1.statut', 'rupture')
            );
    }

    public function test_les_filtres_site_recherche_categorie_et_statut_sont_appliques(): void
    {
        $categorie = Categorie::create([
            'organization_id' => $this->organization->id,
            'nom' => 'Boissons',
        ]);
        $produit = $this->makeProduitAvecVariante($this->organization, [
            'nom' => 'Eau minérale',
            'categorie_id' => $categorie->id,
            'alerte_stock_active' => true,
            'seuil_alerte_stock' => 10,
        ], ['sku' => 'EAU-FILTRE-001']);
        $this->stock($produit->variantePrincipale()->first(), $this->siteA, 5);

        $autre = $this->makeProduitAvecVariante($this->organization, [
            'nom' => 'Carton vide',
        ], ['sku' => 'CARTON-001']);
        $this->stock($autre->variantePrincipale()->first(), $this->siteA, 20);

        $this->actingAs($this->admin)
            ->get(route('produits.stock.index', [
                'site_ids' => [$this->siteA->id],
                'search' => 'EAU-FILTRE',
                'categorie_id' => [$categorie->id],
                'stock_statut' => ['stock_faible'],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stocks.data', 1)
                ->where('stocks.data.0.produit_id', $produit->id)
                ->where('stocks.data.0.site_id', $this->siteA->id)
                ->where('filters.site_ids.0', $this->siteA->id)
            );
    }

    public function test_un_non_admin_ne_voit_que_ses_sites_meme_si_un_autre_site_est_demande(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $this->organization->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('produits.read');
        $manager->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        $produit = $this->makeProduitAvecVariante($this->organization, [], ['sku' => 'SCOPE-001']);
        $variante = $produit->variantePrincipale()->first();
        $this->stock($variante, $this->siteA, 7);
        $this->stock($variante, $this->siteB, 99);

        $this->actingAs($manager)
            ->get(route('produits.stock.index', ['site_ids' => [$this->siteB->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stocks.data', 1)
                ->where('stocks.data.0.site_id', $this->siteA->id)
                ->where('stocks.data.0.qte_stock', 7)
                ->where('filters.site_ids.0', $this->siteA->id)
            );
    }

    public function test_aucune_donnee_dune_autre_organisation_nest_retournee(): void
    {
        $autreOrganisation = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($autreOrganisation->id);
        $autreSite = Site::factory()->create(['organization_id' => $autreOrganisation->id]);
        $autreProduit = $this->makeProduitAvecVariante($autreOrganisation, [
            'nom' => 'Produit secret',
        ], ['sku' => 'SECRET-001']);
        $this->stock($autreProduit->variantePrincipale()->first(), $autreSite, 50);

        $this->actingAs($this->admin)
            ->get(route('produits.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('stocks.data', 0));
    }

    public function test_le_droit_dajustement_est_expose_uniquement_sur_les_sites_autorises(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $this->organization->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('produits.read');
        $manager->sites()->attach([
            $this->siteA->id => ['role' => 'employe', 'is_default' => true],
            $this->siteB->id => ['role' => 'employe', 'is_default' => false],
        ]);
        DroitAjustementStock::create([
            'organization_id' => $this->organization->id,
            'role_name' => 'manager',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$this->siteA->id],
            'peut_augmenter' => true,
            'peut_diminuer' => false,
        ]);
        $this->makeProduitAvecVariante($this->organization, [], ['sku' => 'DROIT-001']);

        $this->actingAs($manager)
            ->get(route('produits.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stocks.data', 2)
                ->where('stocks.data.0.site_id', $this->siteA->id)
                ->where('stocks.data.0.can_ajuster', true)
                ->where('stocks.data.1.site_id', $this->siteB->id)
                ->where('stocks.data.1.can_ajuster', false)
                ->where('can_augmenter_stock', true)
                ->where('can_diminuer_stock', false)
            );
    }

    public function test_historique_est_filtre_par_variante_et_site_et_protege_le_scope_site(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $this->organization->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('produits.read');
        $manager->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        $produit = $this->makeProduitAvecVariante($this->organization, [], ['sku' => 'HIST-001']);
        $variante = $produit->variantePrincipale()->first();
        $this->mouvement($variante, $this->siteA, 4, $manager);
        $this->mouvement($variante, $this->siteB, 9, $manager);

        $this->actingAs($manager)
            ->getJson(route('produits.historique', $produit).'?variante_id='.$variante->id.'&site_id='.$this->siteA->id)
            ->assertOk()
            ->assertJsonCount(1, 'ajustements')
            ->assertJsonPath('ajustements.0.site_nom', $this->siteA->nom);

        $this->actingAs($manager)
            ->getJson(route('produits.historique', $produit).'?variante_id='.$variante->id.'&site_id='.$this->siteB->id)
            ->assertNotFound();
    }

    private function stock(ProduitVariante $variante, Site $site, int $quantite): void
    {
        VarianteStock::create([
            'organization_id' => $variante->organization_id,
            'produit_variante_id' => $variante->id,
            'site_id' => $site->id,
            'qte_stock' => $quantite,
        ]);
    }

    private function mouvement(ProduitVariante $variante, Site $site, int $quantite, User $user): void
    {
        MouvementStock::create([
            'organization_id' => $variante->organization_id,
            'produit_variante_id' => $variante->id,
            'site_id' => $site->id,
            'type' => 'entree',
            'quantite' => $quantite,
            'stock_avant' => 0,
            'stock_apres' => $quantite,
            'created_by' => $user->id,
        ]);
    }
}
