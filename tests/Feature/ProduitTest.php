<?php

namespace Tests\Feature;

use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitStock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ProduitTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['produits.read', 'produits.create', 'produits.update', 'produits.delete']);
    }

    private function defaultSite(): Site
    {
        return $this->user->sites()->wherePivot('is_default', true)->first();
    }

    /** Crée un utilisateur NON admin, affecté à un site de l'organisation courante. */
    private function makeNonAdminUserOnSite(Site $site): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.update', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo('produits.update');
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('produits.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('produits.index'))
            ->assertStatus(403);
    }

    public function test_index_filtre_par_type(): void
    {
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit materiel',
            'type' => 'materiel',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit service',
            'type' => 'service',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['type' => 'materiel']));

        $response->assertStatus(200);
        $produits = $response->original->getData()['page']['props']['produits'];
        $this->assertCount(1, $produits);
        $this->assertSame('Produit materiel', $produits[0]['nom']);
    }

    public function test_index_filtre_par_statut(): void
    {
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Actif',
            'type' => 'materiel',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Archivé',
            'type' => 'materiel',
            'statut' => 'archive',
            'is_alerte' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['statut' => 'actif']));

        $response->assertStatus(200);
        $produits = $response->original->getData()['page']['props']['produits'];
        $this->assertCount(1, $produits);
        $this->assertSame('Actif', $produits[0]['nom']);
    }

    public function test_index_inclut_sites_et_options(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('produits.index'));

        $response->assertStatus(200);
        $props = $response->original->getData()['page']['props'];
        $this->assertArrayHasKey('sites', $props);
        $this->assertArrayHasKey('types', $props);
        $this->assertArrayHasKey('statuts', $props);
        $this->assertArrayHasKey('filters', $props);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_produit_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Rouleau plastique',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'qte_stock' => 100,
                'is_alerte' => false,
            ])
            ->assertRedirect(route('produits.index'));

        $this->assertDatabaseHas('produits', [
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [])
            ->assertSessionHasErrors(['nom', 'type', 'statut']);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Test',
                'type' => 'type_invalide',
                'statut' => 'actif',
            ])
            ->assertSessionHasErrors('type');
    }

    private function makeProduit(Organization $org, int $qteStock = 50): Produit
    {
        return Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 500,
            'qte_stock' => $qteStock,
            'is_alerte' => false,
        ]);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->get(route('produits.show', $produit))
            ->assertStatus(200);
    }

    public function test_show_inclut_stocks_par_site(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 30,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.show', $produit));

        $response->assertStatus(200);
        $props = $response->original->getData()['page']['props'];
        $this->assertCount(1, $props['produit']['stocks_par_site']);
        $this->assertSame(30, $props['produit']['stocks_par_site'][0]['qte_stock']);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->actingAs($this->user)
            ->get(route('produits.show', $produit))
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->get(route('produits.edit', $produit))
            ->assertStatus(200);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_produit_and_redirects(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => 'Nouveau nom produit',
                'type' => 'materiel',
                'statut' => 'actif',
                'is_alerte' => false,
            ])
            ->assertRedirect(route('produits.index'));

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [])
            ->assertSessionHasErrors(['nom', 'type', 'statut']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_produit_and_redirects(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->delete(route('produits.destroy', $produit))
            ->assertRedirect(route('produits.index'));

        $this->assertSoftDeleted('produits', ['id' => $produit->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('produits.destroy', $produit))
            ->assertStatus(403);
    }

    // ── ajuster-stock ─────────────────────────────────────────────────────────

    public function test_ajuster_stock_augmente_le_stock(): void
    {
        $produit = $this->makeProduit($this->org, 50);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 20,
                'motif_type' => 'apres_production',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 70,
        ]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'type' => 'entree',
            'quantite' => 20,
            'stock_avant' => 50,
            'stock_apres' => 70,
        ]);
    }

    public function test_ajuster_stock_diminue_le_stock(): void
    {
        $produit = $this->makeProduit($this->org, 50);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 15,
                'motif_type' => 'perte',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 35,
        ]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'type' => 'sortie',
            'quantite' => 15,
            'stock_avant' => 50,
            'stock_apres' => 35,
        ]);
    }

    public function test_ajuster_stock_cree_produit_stock_par_site(): void
    {
        $produit = $this->makeProduit($this->org, 50);
        $site = $this->defaultSite();

        $this->assertDatabaseMissing('produit_stocks', ['produit_id' => $produit->id]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_stocks', [
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 60, // 50 migré + 10 ajout
        ]);
    }

    public function test_ajuster_stock_agrege_stock_total_sur_plusieurs_sites(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site1 = $this->defaultSite();
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        // Stock site 1 : 30
        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site1->id,
            'qte_stock' => 30,
        ]);

        // Stock site 2 : 20
        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site2->id,
            'qte_stock' => 20,
        ]);

        // Ajout de 10 sur site1 → site1 = 40, total = 60
        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site1->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', ['id' => $produit->id, 'qte_stock' => 60]);
        $this->assertDatabaseHas('produit_stocks', ['produit_id' => $produit->id, 'site_id' => $site1->id, 'qte_stock' => 40]);
        $this->assertDatabaseHas('produit_stocks', ['produit_id' => $produit->id, 'site_id' => $site2->id, 'qte_stock' => 20]);
    }

    public function test_ajuster_stock_enregistre_le_motif(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'notes' => 'Correction de stock',
        ]);
    }

    public function test_ajuster_stock_echoue_sans_site_id(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_ajuster_stock_echoue_avec_site_autre_organisation(): void
    {
        $produit = $this->makeProduit($this->org);
        $otherOrg = Organization::factory()->create();
        $otherSite = Site::create([
            'organization_id' => $otherOrg->id,
            'nom' => 'Site autre org',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $otherSite->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertStatus(404);
    }

    public function test_ajuster_stock_echoue_si_deux_champs_renseignes(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'diminuer' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('augmenter');
    }

    public function test_ajuster_stock_echoue_si_aucun_champ_renseigne(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('augmenter');
    }

    public function test_ajuster_stock_echoue_si_quantite_nulle_ou_negative(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 0,
            ])
            ->assertSessionHasErrors('augmenter');

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => -5,
            ])
            ->assertSessionHasErrors('diminuer');
    }

    public function test_ajuster_stock_echoue_si_retrait_depasse_stock_du_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);
        $produit->update(['qte_stock' => 50]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 100,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('diminuer');

        $this->assertDatabaseHas('produit_stocks', [
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);
    }

    public function test_ajuster_stock_retourne_403_pour_autre_organisation(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
            ])
            ->assertStatus(403);
    }

    public function test_ajuster_stock_ne_cree_pas_mouvement_si_validation_echoue(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);

        $countBefore = MouvementStock::where('produit_id', $produit->id)->count();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 9999,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('diminuer');

        $this->assertSame($countBefore, MouvementStock::where('produit_id', $produit->id)->count());
    }

    // ── historique ────────────────────────────────────────────────────────────

    public function test_historique_retourne_ajustements_et_modifications(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('produits.historique', $produit));

        $response->assertStatus(200)
            ->assertJsonStructure(['ajustements', 'modifications']);

        $this->assertNotEmpty($response->json('ajustements'));
    }

    // ── Sécurité multi-agences ────────────────────────────────────────────────

    public function test_admin_peut_ajuster_stock_sur_nimporte_quel_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);

        $autresSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site secondaire',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $autresSite->id,
            'qte_stock' => 20,
        ]);

        // $this->user est admin_entreprise (via makeUserWithPermissions)
        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $autresSite->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_stocks', [
            'produit_id' => $produit->id,
            'site_id' => $autresSite->id,
            'qte_stock' => 25,
        ]);
    }

    public function test_non_admin_peut_ajuster_stock_de_son_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 30,
        ]);

        $employe = $this->makeNonAdminUserOnSite($site);

        $this->actingAs($employe)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_stocks', [
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 40,
        ]);
    }

    public function test_non_admin_ne_peut_pas_ajuster_stock_dun_autre_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $siteEmploye = $this->defaultSite();

        $siteInterdit = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site interdit',
            'type' => 'depot',
            'localisation' => 'Labé',
        ]);

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $siteInterdit->id,
            'qte_stock' => 50,
        ]);

        $employe = $this->makeNonAdminUserOnSite($siteEmploye);

        $this->actingAs($employe)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $siteInterdit->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertStatus(403);

        // Le stock ne doit pas avoir changé
        $this->assertDatabaseHas('produit_stocks', [
            'produit_id' => $produit->id,
            'site_id' => $siteInterdit->id,
            'qte_stock' => 50,
        ]);
    }

    public function test_ajustement_modifie_uniquement_le_site_selectionne(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site1 = $this->defaultSite();
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Mamou',
        ]);

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site1->id,
            'qte_stock' => 100,
        ]);
        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site2->id,
            'qte_stock' => 200,
        ]);

        // Ajustement sur site1 uniquement
        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site1->id,
                'augmenter' => 50,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_stocks', [
            'produit_id' => $produit->id,
            'site_id' => $site1->id,
            'qte_stock' => 150,
        ]);
        // Site2 inchangé
        $this->assertDatabaseHas('produit_stocks', [
            'produit_id' => $produit->id,
            'site_id' => $site2->id,
            'qte_stock' => 200,
        ]);
    }

    public function test_historique_modifications_exclut_stock_adjusted(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);

        // Ajustement stock → crée un AuditLog STOCK_ADJUSTED
        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('produits.historique', $produit));

        $response->assertOk();
        $eventCodes = array_column($response->json('modifications'), 'event_code');
        $this->assertNotContains('stock_adjusted', $eventCodes);
    }

    // ── Indicateur de tendance stock ──────────────────────────────────────────

    public function test_index_inclut_last_mouvement_entree(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 30,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 15,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)->get(route('produits.index'));
        $response->assertStatus(200);

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertSame('entree', $found['last_mouvement_type']);
        $this->assertSame(15, $found['last_mouvement_quantite']);
    }

    public function test_index_inclut_last_mouvement_sortie(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 100,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 20,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)->get(route('produits.index'));
        $response->assertStatus(200);

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertSame('sortie', $found['last_mouvement_type']);
        $this->assertSame(20, $found['last_mouvement_quantite']);
    }

    public function test_index_last_mouvement_null_si_aucun_ajustement(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'qte_stock' => 10,
        ]);

        $response = $this->actingAs($this->user)->get(route('produits.index'));
        $response->assertStatus(200);

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertNull($found['last_mouvement_type']);
        $this->assertNull($found['last_mouvement_quantite']);
    }

    public function test_index_last_mouvement_filtre_par_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site1 = $this->defaultSite();
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Mamou',
        ]);

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site1->id,
            'qte_stock' => 50,
        ]);
        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $site2->id,
            'qte_stock' => 50,
        ]);

        // Entrée sur site1
        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site1->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ]);

        // Sortie sur site2
        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site2->id,
                'diminuer' => 5,
                'motif_type' => 'correction_stock',
            ]);

        // Filtrer sur site1 → doit voir l'entrée de site1, pas la sortie de site2
        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['site_id' => $site1->id]));

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertSame('entree', $found['last_mouvement_type']);
        $this->assertSame(10, $found['last_mouvement_quantite']);
    }
}
