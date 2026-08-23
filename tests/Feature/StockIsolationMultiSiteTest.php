<?php

namespace Tests\Feature;

use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\MouvementStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Non-régression multi-agences : le stock d'une agence ne doit jamais être lu,
 * écrit, ou visible dans une autre — ni via l'affichage (ProduitController::index()),
 * ni via l'ajustement manuel, ni via une vente/sortie générique. Couvre aussi le
 * cas du stock legacy (Produit::qte_stock) jamais hérité implicitement par la
 * première agence touchée.
 */
class StockIsolationMultiSiteTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $siteA;

    private Site $siteB;

    private Site $siteC;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->siteA = $this->makeSite('Matoto');
        $this->siteB = $this->makeSite('Sonfonia');
        $this->siteC = $this->makeSite('Cimenterie');

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->admin->assignRole('admin_entreprise');
        $this->admin->givePermissionTo('produits.read');
        $this->admin->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function seedStock(Produit $produit, Site $site, int $qte): VarianteStock
    {
        return VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $site->id,
            'qte_stock' => $qte,
        ]);
    }

    // ── Test A — Affichage isolé par agence ──────────────────────────────────

    public function test_liste_produits_filtree_par_agence_naffiche_que_le_stock_de_cette_agence(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 10);
        $this->seedStock($produit, $this->siteB, 25);

        $this->actingAs($this->admin)
            ->get(route('produits.index', ['site_ids' => [$this->siteA->id]]))
            ->assertInertia(fn ($page) => $page->where('produits.0.qte_stock', 10));

        $this->actingAs($this->admin)
            ->get(route('produits.index', ['site_ids' => [$this->siteB->id]]))
            ->assertInertia(fn ($page) => $page->where('produits.0.qte_stock', 25));
    }

    // ── Test B — Agence sans stock : jamais le stock global ─────────────────

    public function test_agence_sans_ligne_variante_stock_affiche_zero_jamais_le_stock_global(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 10);
        // Le legacy qte_stock du produit reflète naturellement l'agrégat (10) après
        // resynchronisation — siteB, lui, n'a AUCUNE ligne VarianteStock.
        $produit->resynchroniserQteStock();
        $this->assertGreaterThan(0, $produit->fresh()->qte_stock);

        $this->actingAs($this->admin)
            ->get(route('produits.index', ['site_ids' => [$this->siteB->id]]))
            ->assertInertia(fn ($page) => $page->where('produits.0.qte_stock', 0));
    }

    // ── Test C — Ajustement isolé ─────────────────────────────────────────────

    public function test_ajustement_sur_une_agence_naffecte_pas_les_autres(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 10);
        $this->seedStock($produit, $this->siteB, 20);

        $this->actingAs($this->admin)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $this->siteA->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $variante = $produit->variantePrincipale()->first();
        $this->assertEquals(15, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteA->id)->value('qte_stock'));
        $this->assertEquals(20, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteB->id)->value('qte_stock'));
    }

    // ── Test D — Ajustement d'une agence vide : ne récupère jamais le stock legacy ──

    public function test_premier_ajustement_sur_une_agence_vide_ne_recupere_pas_le_stock_dune_autre_agence(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 100);
        $produit->resynchroniserQteStock(); // Produit::qte_stock = 100 (agrégat)

        // Site B n'a jamais eu de ligne VarianteStock — c'est la toute première
        // fois qu'il est touché pour ce produit.
        $this->actingAs($this->admin)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $this->siteB->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $variante = $produit->variantePrincipale()->first();
        $this->assertEquals(10, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteB->id)->value('qte_stock'));
        // Jamais 110 : le stock legacy (100, sur A) ne doit jamais être hérité par B.
        $this->assertEquals(100, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteA->id)->value('qte_stock'));
    }

    // ── Test E — Stock global = agrégat ──────────────────────────────────────

    public function test_qte_stock_produit_est_lagregat_de_tous_les_sites(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 15);
        $this->seedStock($produit, $this->siteB, 20);

        $produit->resynchroniserQteStock();

        $this->assertEquals(35, $produit->fresh()->qte_stock);
    }

    // ── Test F — Sortie générique (vente) isolée par site ────────────────────

    public function test_sortie_stock_generique_ne_decremente_que_le_site_concerne(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 100);
        $this->seedStock($produit, $this->siteB, 50);
        $variante = $produit->variantePrincipale()->first();

        MouvementStockService::sortirStock(
            varianteId: $variante->id,
            siteId: $this->siteA->id,
            orgId: $this->org->id,
            quantite: 10,
            sourceType: 'test-vente',
            sourceId: 'ligne-1',
            userId: $this->admin->id,
        );

        $this->assertEquals(90, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteA->id)->value('qte_stock'));
        $this->assertEquals(50, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteB->id)->value('qte_stock'));
    }

    public function test_sortie_stock_generique_sur_site_jamais_touche_ne_recupere_pas_le_legacy(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 500);
        $produit->resynchroniserQteStock();
        $variante = $produit->variantePrincipale()->first();

        // Site B : jamais touché (0 disponible). Depuis le correctif du 23/08/2026
        // (suppression du clamp silencieux), la sortie est désormais REFUSÉE en entier —
        // jamais un repli implicite sur les 500 du legacy de A, jamais un clamp à 0.
        try {
            MouvementStockService::sortirStock(
                varianteId: $variante->id,
                siteId: $this->siteB->id,
                orgId: $this->org->id,
                quantite: 1,
                sourceType: 'test-vente',
                sourceId: 'ligne-2',
                userId: $this->admin->id,
            );
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu
        }

        $this->assertDatabaseMissing('variante_stocks', [
            'produit_variante_id' => $variante->id,
            'site_id' => $this->siteB->id,
        ]);
        $this->assertEquals(500, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteA->id)->value('qte_stock'));
    }

    // ── Test G — Motif obligatoire (trim) côté backend ───────────────────────

    public function test_ajustement_avec_motif_null_est_refuse(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 10);

        $this->actingAs($this->admin)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $this->siteA->id,
                'augmenter' => 5,
            ])
            ->assertSessionHasErrors('motif_type');

        $this->assertEquals(10, VarianteStock::where('site_id', $this->siteA->id)->value('qte_stock'));
        $this->assertEquals(0, MouvementStock::count());
    }

    public function test_ajustement_avec_motif_detail_vide_pour_autre_est_refuse(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 10);

        $this->actingAs($this->admin)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $this->siteA->id,
                'augmenter' => 5,
                'motif_type' => 'autre',
                'motif_detail' => '',
            ])
            ->assertSessionHasErrors('motif_detail');

        $this->assertEquals(10, VarianteStock::where('site_id', $this->siteA->id)->value('qte_stock'));
        $this->assertEquals(0, MouvementStock::count());
    }

    public function test_ajustement_avec_motif_detail_espaces_uniquement_pour_autre_est_refuse(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 10);

        $this->actingAs($this->admin)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $this->siteA->id,
                'augmenter' => 5,
                'motif_type' => 'autre',
                'motif_detail' => '     ',
            ])
            ->assertSessionHasErrors('motif_detail');

        $this->assertEquals(10, VarianteStock::where('site_id', $this->siteA->id)->value('qte_stock'));
        $this->assertEquals(0, MouvementStock::count());
    }

    public function test_ajustement_avec_motif_valide_fonctionne(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 10);

        $this->actingAs($this->admin)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $this->siteA->id,
                'augmenter' => 5,
                'motif_type' => 'autre',
                'motif_detail' => 'Correction inventaire',
            ])
            ->assertRedirect();

        $this->assertEquals(15, VarianteStock::where('site_id', $this->siteA->id)->value('qte_stock'));
        $this->assertEquals(1, MouvementStock::count());
    }

    // ── Résultat fonctionnel attendu (scénario complet, section 20) ─────────

    public function test_scenario_complet_trois_agences_isolation_de_bout_en_bout(): void
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack Bouteilles']);
        $this->seedStock($produit, $this->siteA, 100);
        $this->seedStock($produit, $this->siteB, 50);
        $this->seedStock($produit, $this->siteC, 25);
        $produit->resynchroniserQteStock();

        $this->actingAs($this->admin)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $this->siteB->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $variante = $produit->variantePrincipale()->first();
        $this->assertEquals(100, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteA->id)->value('qte_stock'));
        $this->assertEquals(60, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteB->id)->value('qte_stock'));
        $this->assertEquals(25, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteC->id)->value('qte_stock'));
        $this->assertEquals(185, $produit->fresh()->qte_stock);

        // Filtrage : chaque agence affiche strictement son propre stock.
        $this->actingAs($this->admin)
            ->get(route('produits.index', ['site_ids' => [$this->siteA->id]]))
            ->assertInertia(fn ($page) => $page->where('produits.0.qte_stock', 100));
        $this->actingAs($this->admin)
            ->get(route('produits.index', ['site_ids' => [$this->siteB->id]]))
            ->assertInertia(fn ($page) => $page->where('produits.0.qte_stock', 60));
        $this->actingAs($this->admin)
            ->get(route('produits.index', ['site_ids' => [$this->siteC->id]]))
            ->assertInertia(fn ($page) => $page->where('produits.0.qte_stock', 25));

        // Une agence qui n'a jamais eu ce produit : 0, jamais le total global.
        $siteD = $this->makeSite('Nouvelle agence');
        $this->actingAs($this->admin)
            ->get(route('produits.index', ['site_ids' => [$siteD->id]]))
            ->assertInertia(fn ($page) => $page->where('produits.0.qte_stock', 0));
    }
}
