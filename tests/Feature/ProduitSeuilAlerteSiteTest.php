<?php

namespace Tests\Feature;

use App\Enums\StockStatut;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitSeuilAlerte;
use App\Models\ProduitType;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\ProduitService;
use App\Services\StockStatutService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Fiche produit > Alerte de stock faible par AGENCE (chantier 29/08/2026) : chaque site actif
 * de l'organisation peut avoir son propre seuil, avec repli sur le seuil global de
 * l'organisation quand aucun seuil spécifique n'est défini pour ce site. Couvre le formulaire
 * web (ProduitController::edit()/update()) et le contrôle du stock avec le bon site_id.
 */
class ProduitSeuilAlerteSiteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $matoto;

    private Site $cba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['produits.read', 'produits.create', 'produits.update']);
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Matoto']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot', 'localisation' => 'CBA']);
    }

    private function typeId(string $code = 'materiel'): string
    {
        return ProduitType::where('organization_id', $this->org->id)->where('code', $code)->value('id');
    }

    private function makeProduit(): Produit
    {
        return app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit test',
            'produit_type_id' => $this->typeId(),
            'statut' => 'actif',
            'prix_achat' => 500,
            'alerte_stock_active' => true,
        ]);
    }

    private function updatePayload(Produit $produit, array $seuilsSite): array
    {
        return [
            'nom' => $produit->nom,
            'produit_type_id' => $produit->produit_type_id,
            'statut' => 'actif',
            'alerte_stock_active' => true,
            'seuils_site' => $seuilsSite,
        ];
    }

    // ── edit() : props exposées au formulaire ───────────────────────────────

    public function test_edit_expose_les_sites_actifs_et_les_seuils_deja_enregistres(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'seuil_alerte_stock' => 1000,
        ]);

        $response = $this->actingAs($this->user)->get(route('produits.edit', $produit));
        $props = $response->original->getData()['page']['props'];

        $siteIds = collect($props['sites'])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $this->matoto->id, $siteIds);
        $this->assertContains((string) $this->cba->id, $siteIds);
        $this->assertSame(1000, $props['seuilsAlerteSite'][$this->matoto->id]);
        $this->assertArrayNotHasKey((string) $this->cba->id, $props['seuilsAlerteSite']);
    }

    public function test_edit_najoute_pas_les_sites_inactifs(): void
    {
        $inactif = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site fermé', 'type' => 'depot', 'localisation' => 'X', 'statut' => 'inactive']);
        $produit = $this->makeProduit();

        $response = $this->actingAs($this->user)->get(route('produits.edit', $produit));
        $props = $response->original->getData()['page']['props'];

        $siteIds = collect($props['sites'])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertNotContains((string) $inactif->id, $siteIds);
    }

    // ── update() : seuil spécifique Matoto / CBA ────────────────────────────

    public function test_update_definit_un_seuil_specifique_pour_matoto(): void
    {
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'seuil' => 1000],
            ]))
            ->assertRedirect(route('produits.show', $produit));

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'seuil_alerte_stock' => 1000,
        ]);
    }

    public function test_update_accepte_un_seuil_different_pour_matoto_et_cba(): void
    {
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'seuil' => 1000],
                ['site_id' => $this->cba->id, 'seuil' => 300],
            ]))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $this->matoto->id, 'seuil_alerte_stock' => 1000]);
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $this->cba->id, 'seuil_alerte_stock' => 300]);
    }

    public function test_update_modifie_un_seuil_existant_sans_creer_de_doublon(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'seuil_alerte_stock' => 500,
        ]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'seuil' => 800],
            ]));

        $this->assertSame(1, ProduitSeuilAlerte::where('produit_id', $produit->id)->where('site_id', $this->matoto->id)->count());
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $this->matoto->id, 'seuil_alerte_stock' => 800]);
    }

    public function test_update_avec_seuil_vide_supprime_le_seuil_specifique_et_revient_au_defaut(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'seuil_alerte_stock' => 500,
        ]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'seuil' => null],
            ]));

        $this->assertDatabaseMissing('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $this->matoto->id]);
        $this->assertSame(10, app(StockStatutService::class)->seuilEffectifPourSite($produit->fresh(), $this->matoto->id));
    }

    public function test_update_replie_sur_le_seuil_global_quand_aucun_seuil_specifique(): void
    {
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, []));

        $this->assertSame(10, app(StockStatutService::class)->seuilEffectifPourSite($produit->fresh(), $this->cba->id));
    }

    // ── isolation entre organisations ───────────────────────────────────────

    public function test_update_refuse_un_site_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteAutreOrg = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Site étranger', 'type' => 'depot', 'localisation' => 'Z']);
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $siteAutreOrg->id, 'seuil' => 1000],
            ]))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $siteAutreOrg->id]);
    }

    // ── contrôle du stock avec le bon site_id ───────────────────────────────

    public function test_le_controle_de_stock_utilise_le_seuil_du_bon_site(): void
    {
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->firstOrFail();
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Eau minérale',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
            'alerte_stock_active' => true,
        ]);
        $variante = $produit->variantePrincipale()->first();

        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'seuil_alerte_stock' => 1000,
        ]);
        // CBA n'a aucun seuil spécifique : repli sur le seuil global (10 par défaut).

        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $this->matoto->id, 'qte_stock' => 900]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $this->cba->id, 'qte_stock' => 900]);

        $response = $this->actingAs($this->user)->get(route('produits.show', $produit));
        $props = $response->original->getData()['page']['props'];
        $stocksParSite = collect($props['produit']['stocks_par_site'])->keyBy('site_id');

        // Même quantité (900) sur les deux sites, mais un seul est en alerte : celui dont le
        // seuil spécifique (1000) dépasse la quantité — jamais le seuil de l'AUTRE site.
        $this->assertSame(1000, $stocksParSite[$this->matoto->id]['seuil_effectif']);
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $stocksParSite[$this->matoto->id]['statut']);
        $this->assertSame(10, $stocksParSite[$this->cba->id]['seuil_effectif']);
        $this->assertSame(StockStatut::DISPONIBLE->value, $stocksParSite[$this->cba->id]['statut']);
    }
}
