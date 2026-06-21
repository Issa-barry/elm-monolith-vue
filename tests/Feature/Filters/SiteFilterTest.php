<?php

namespace Tests\Feature\Filters;

use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Produit;
use App\Models\ProduitStock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Vérifie que le filtre Agence/Site respecte les règles métier :
 *  - Admin sans site_ids → voit toutes les agences
 *  - Admin avec site_ids[] → voit seulement les agences sélectionnées
 *  - Non-admin → voit seulement ses agences, quoi qu'on envoie en paramètre
 */
class SiteFilterTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'produits.read']);

        $this->siteA = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Alpha',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->siteB = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Beta',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
    }

    private function makeNonAdmin(Site ...$sites): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ventes.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo('ventes.read', 'produits.read');
        foreach ($sites as $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => false]);
        }

        return $user;
    }

    private function makeFacture(Site $site): FactureVente
    {
        static $seq = 0;
        $seq++;
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
        ]);

        return FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'statut_facture' => 'impayee',
        ]);
    }

    // ── Factures ──────────────────────────────────────────────────────────────

    public function test_admin_without_site_filter_sees_all_factures(): void
    {
        $this->makeFacture($this->siteA);
        $this->makeFacture($this->siteB);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page->has('factures', 2));
    }

    public function test_admin_with_site_ids_sees_only_selected_factures(): void
    {
        $this->makeFacture($this->siteA);
        $this->makeFacture($this->siteB);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'site_ids' => [$this->siteA->id]]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page->has('factures', 1));
    }

    public function test_non_admin_sees_only_their_site_factures(): void
    {
        $this->makeFacture($this->siteA);
        $this->makeFacture($this->siteB);

        $nonAdmin = $this->makeNonAdmin($this->siteA);

        $this->actingAs($nonAdmin)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page->has('factures', 1));
    }

    public function test_non_admin_cannot_bypass_scope_with_site_ids(): void
    {
        $this->makeFacture($this->siteA);
        $this->makeFacture($this->siteB);

        $nonAdmin = $this->makeNonAdmin($this->siteA);

        // Tenter d'accéder aux données de siteB via site_ids[]
        $this->actingAs($nonAdmin)
            ->get(route('factures.index', ['periode' => 'all', 'site_ids' => [$this->siteB->id]]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('factures', 1), // uniquement siteA (son périmètre)
            );
    }

    // ── Ventes ────────────────────────────────────────────────────────────────

    public function test_admin_without_site_ids_sees_all_commandes(): void
    {
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteA->id]);
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteB->id]);

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page->has('commandes', 2));
    }

    public function test_admin_with_site_ids_sees_only_selected_commandes(): void
    {
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteA->id]);
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteB->id]);

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['periode' => 'all', 'site_ids' => [$this->siteA->id]]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page->has('commandes', 1));
    }

    public function test_non_admin_sees_only_their_site_commandes(): void
    {
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteA->id]);
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteB->id]);

        $nonAdmin = $this->makeNonAdmin($this->siteA);

        $this->actingAs($nonAdmin)
            ->get(route('ventes.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page->has('commandes', 1));
    }

    public function test_non_admin_cannot_bypass_scope_with_site_ids_on_ventes(): void
    {
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteA->id]);
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteB->id]);

        $nonAdmin = $this->makeNonAdmin($this->siteA);

        $this->actingAs($nonAdmin)
            ->get(route('ventes.index', ['periode' => 'all', 'site_ids' => [$this->siteB->id]]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page->has('commandes', 1));
    }

    // ── Produits : stock par agence ───────────────────────────────────────────

    private function makeProduit(): Produit
    {
        return Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit Test',
            'type' => 'fabricable',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);
    }

    private function makeProduitStock(Produit $produit, Site $site, int $qte): ProduitStock
    {
        return ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => $qte,
        ]);
    }

    public function test_produit_stock_filtrage_site_unique(): void
    {
        $produit = $this->makeProduit();
        $this->makeProduitStock($produit, $this->siteA, 100);
        $this->makeProduitStock($produit, $this->siteB, 50);

        $this->actingAs($this->user)
            ->get(route('produits.index', ['site_ids' => [$this->siteA->id]]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('produits', 1)
                ->where('produits.0.qte_stock', 100)
                ->where('filters.site_ids', [(string) $this->siteA->id]),
            );
    }

    public function test_produit_stock_filtrage_multi_sites_somme_correcte(): void
    {
        $produit = $this->makeProduit();
        $this->makeProduitStock($produit, $this->siteA, 100);
        $this->makeProduitStock($produit, $this->siteB, 50);

        $this->actingAs($this->user)
            ->get(route('produits.index', ['site_ids' => [$this->siteA->id, $this->siteB->id]]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('produits', 1)
                ->where('produits.0.qte_stock', 150), // 100 + 50
            );
    }

    public function test_produit_stock_sans_filtre_retourne_total(): void
    {
        $produit = $this->makeProduit();
        $this->makeProduitStock($produit, $this->siteA, 100);
        $this->makeProduitStock($produit, $this->siteB, 50);

        $this->actingAs($this->user)
            ->get(route('produits.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('produits', 1)
                ->where('produits.0.qte_stock', 150),
            );
    }

    public function test_non_admin_produit_stock_affiche_son_site(): void
    {
        $produit = $this->makeProduit();
        $this->makeProduitStock($produit, $this->siteA, 100);
        $this->makeProduitStock($produit, $this->siteB, 50);

        $nonAdmin = $this->makeNonAdmin($this->siteA);

        $this->actingAs($nonAdmin)
            ->get(route('produits.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('produits', 1)
                ->where('produits.0.qte_stock', 100)
                ->where('filters.site_ids', [(string) $this->siteA->id]),
            );
    }

    // ── Middleware : user_sites partagé ───────────────────────────────────────

    public function test_admin_receives_empty_user_sites_in_shared_props(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user_sites', []),
            );
    }

    public function test_non_admin_receives_their_sites_in_shared_props(): void
    {
        $nonAdmin = $this->makeNonAdmin($this->siteA);

        $this->actingAs($nonAdmin)
            ->get(route('factures.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth.user_sites', 1)
                ->where('auth.user_sites.0.id', (string) $this->siteA->id),
            );
    }
}
