<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SavedFilter;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

class StockSavedViewsTest extends TestCase
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
        $this->siteA = Site::factory()->create(['organization_id' => $this->organization->id, 'nom' => 'Agence Alpha']);
        $this->siteB = Site::factory()->create(['organization_id' => $this->organization->id, 'nom' => 'Agence Beta']);

        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.update', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->admin->assignRole('admin_entreprise');
        $this->admin->givePermissionTo('produits.read');
        $this->admin->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        $this->makeProduitAvecVariante($this->organization, ['nom' => 'Bidon premium'], ['sku' => 'BIDON-001']);
        $this->makeProduitAvecVariante($this->organization, ['nom' => 'Sachet eau'], ['sku' => 'SACHET-001']);
    }

    private function storeView(User $user, array $payload)
    {
        return $this->actingAs($user)->postJson(route('saved-filters.store', 'stock'), $payload);
    }

    public function test_une_vue_stock_enregistree_est_appliquee_par_la_liste(): void
    {
        $id = $this->storeView($this->admin, [
            'name' => 'Bidons Alpha',
            'visibility' => 'personal',
            'filters' => ['search' => 'Bidon', 'site_ids' => [$this->siteA->id], 'stock_statut' => 'rupture'],
        ])->assertCreated()->json('id');

        $this->actingAs($this->admin)
            ->get(route('produits.stock.index', ['saved_view' => $id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved_view.id', $id)
                ->where('filters.search', 'Bidon')
                ->where('filters.stock_statut', 'rupture')
                ->has('stocks.data', 1)
                ->where('stocks.data.0.produit_nom', 'Bidon premium')
                ->where('stocks.data.0.site_id', $this->siteA->id));
    }

    public function test_la_vue_par_defaut_sapplique_sans_parametre_mais_pas_apres_reinitialisation(): void
    {
        $this->storeView($this->admin, [
            'name' => 'Sachets',
            'visibility' => 'personal',
            'filters' => ['search' => 'Sachet'],
            'is_default' => true,
        ])->assertCreated();

        $this->actingAs($this->admin)->get(route('produits.stock.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved_view.name', 'Sachets')
                ->has('stocks.data', 2)
                ->where('stocks.data.0.produit_nom', 'Sachet eau'));

        $this->actingAs($this->admin)->get(route('produits.stock.index', ['all' => '1']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved_view', null)
                ->has('stocks.data', 4));
    }

    public function test_les_vues_stock_et_produits_sont_isolees(): void
    {
        $this->storeView($this->admin, ['name' => 'Stock', 'visibility' => 'personal', 'filters' => ['search' => 'Bidon']])->assertCreated();

        $this->actingAs($this->admin)->getJson(route('saved-filters.index', 'produits'))
            ->assertOk()->assertJsonCount(0, 'views');
        $this->actingAs($this->admin)->getJson(route('saved-filters.index', 'stock'))
            ->assertOk()->assertJsonCount(1, 'views');
    }

    public function test_un_critere_propre_a_une_autre_liste_est_refuse(): void
    {
        $this->storeView($this->admin, ['name' => 'Mauvais', 'visibility' => 'personal', 'filters' => ['produit_type_id' => $this->organization->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('filters');
        $this->storeView($this->admin, ['name' => 'Statut inconnu', 'visibility' => 'personal', 'filters' => ['stock_statut' => 'inconnu']])
            ->assertUnprocessable()->assertJsonValidationErrors('filters.stock_statut');
    }

    public function test_un_scope_inconnu_repond_404(): void
    {
        $this->actingAs($this->admin)->getJson('/backoffice/saved-filters/inconnu')->assertNotFound();
    }

    public function test_le_partage_exige_produits_update(): void
    {
        $this->storeView($this->admin, ['name' => 'Partagée', 'visibility' => 'shared', 'filters' => ['search' => 'Bidon']])->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('saved-filters.index', 'stock'))->assertJsonPath('can_share', false);

        $this->admin->givePermissionTo('produits.update');
        $this->storeView($this->admin, ['name' => 'Partagée', 'visibility' => 'shared', 'filters' => ['search' => 'Bidon']])->assertCreated();
    }

    public function test_un_non_admin_ne_peut_pas_enregistrer_une_agence_non_affectee(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        $user->assignRole('manager');
        $user->givePermissionTo('produits.read');
        $user->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        $this->storeView($user, ['name' => 'Beta', 'visibility' => 'personal', 'filters' => ['site_ids' => [$this->siteB->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('filters.site_ids.0');
    }

    public function test_une_vue_dune_autre_organisation_est_introuvable(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::factory()->create(['organization_id' => $autreOrg->id]);
        $autre = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autre->givePermissionTo('produits.read');
        $autre->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);
        $vue = SavedFilter::create([
            'organization_id' => $autreOrg->id,
            'user_id' => $autre->id,
            'scope' => 'stock',
            'name' => 'Externe',
            'visibility' => 'shared',
            'filters' => ['search' => 'Bidon'],
        ]);

        $this->actingAs($this->admin)->get(route('produits.stock.index', ['saved_view' => $vue->id]))->assertNotFound();
    }
}
