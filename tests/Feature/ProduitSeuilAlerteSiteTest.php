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
 * Fiche produit > Alerte de stock faible par AGENCE (chantier 29/08/2026 pour le seuil, étendu le
 * 01/09/2026 à l'ACTIVATION elle-même) : un produit peut n'être géré que dans certains sites —
 * l'activation ("être alerté ?") ET le seuil se règlent indépendamment pour chaque site actif de
 * l'organisation, avec repli sur le seuil global de l'organisation quand aucun seuil spécifique
 * n'est défini sur un site actif. Couvre le formulaire web (ProduitController::edit()/update()) et
 * le contrôle du stock avec le bon site_id.
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
        ]);
    }

    private function updatePayload(Produit $produit, array $seuilsSite): array
    {
        return [
            'nom' => $produit->nom,
            'produit_type_id' => $produit->produit_type_id,
            'statut' => 'actif',
            'seuils_site' => $seuilsSite,
        ];
    }

    // ── edit() : props exposées au formulaire ───────────────────────────────

    public function test_edit_expose_les_sites_actifs_et_la_configuration_deja_enregistree(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 1000,
        ]);

        $response = $this->actingAs($this->user)->get(route('produits.edit', $produit));
        $props = $response->original->getData()['page']['props'];

        $siteIds = collect($props['sites'])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $this->matoto->id, $siteIds);
        $this->assertContains((string) $this->cba->id, $siteIds);
        $this->assertTrue($props['seuilsAlerteSite'][$this->matoto->id]['actif']);
        $this->assertSame(1000, $props['seuilsAlerteSite'][$this->matoto->id]['seuil']);
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

    // ── update() : activation + seuil, indépendants par site ────────────────

    public function test_update_active_cba_et_laisse_lambanyi_desactive(): void
    {
        $lambanyi = Site::create(['organization_id' => $this->org->id, 'nom' => 'Lambanyi', 'type' => 'depot', 'localisation' => 'Lambanyi']);
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->cba->id, 'actif' => true, 'seuil' => 1000],
                ['site_id' => $lambanyi->id, 'actif' => false, 'seuil' => null],
            ]))
            ->assertRedirect(route('produits.show', $produit));

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $produit->id,
            'site_id' => $this->cba->id,
            'actif' => true,
            'seuil_alerte_stock' => 1000,
        ]);
        $this->assertDatabaseMissing('produit_seuils_alerte', [
            'produit_id' => $produit->id,
            'site_id' => $lambanyi->id,
        ]);

        $service = app(StockStatutService::class);
        $produit->load('seuilsAlerte');
        $this->assertTrue($service->alerteActivePourSite($produit, $this->cba->id));
        $this->assertFalse($service->alerteActivePourSite($produit, $lambanyi->id));
    }

    public function test_update_accepte_un_seuil_different_pour_matoto_et_cba(): void
    {
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'actif' => true, 'seuil' => 1000],
                ['site_id' => $this->cba->id, 'actif' => true, 'seuil' => 300],
            ]))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $this->matoto->id, 'actif' => true, 'seuil_alerte_stock' => 1000]);
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $this->cba->id, 'actif' => true, 'seuil_alerte_stock' => 300]);
    }

    public function test_update_modifie_un_seuil_existant_sans_creer_de_doublon(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 500,
        ]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'actif' => true, 'seuil' => 800],
            ]));

        $this->assertSame(1, ProduitSeuilAlerte::where('produit_id', $produit->id)->where('site_id', $this->matoto->id)->count());
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $this->matoto->id, 'seuil_alerte_stock' => 800]);
    }

    public function test_update_avec_seuil_vide_replie_sur_le_defaut_sans_desactiver_le_site(): void
    {
        // Décision produit du 01/09/2026 : un seuil vidé sur un site resté actif n'est jamais
        // bloquant ni une désactivation implicite — il signifie « utiliser le seuil par défaut ».
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 500,
        ]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'actif' => true, 'seuil' => null],
            ]))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => null,
        ]);
        $this->assertSame(10, app(StockStatutService::class)->seuilEffectifPourSite($produit->fresh(), $this->matoto->id));
    }

    public function test_update_desactive_un_site_sans_perdre_son_seuil_specifique(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 500,
        ]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->matoto->id, 'actif' => false, 'seuil' => null],
            ]));

        // La ligne reste en base (seuil préservé), simplement marquée inactive — pas de perte de
        // configuration en cas de réactivation ultérieure.
        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'actif' => false,
            'seuil_alerte_stock' => 500,
        ]);
        $this->assertFalse(app(StockStatutService::class)->alerteActivePourSite($produit->fresh(), $this->matoto->id));
    }

    public function test_update_sans_seuils_site_replie_sur_le_seuil_global_et_reste_inactif(): void
    {
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, []));

        $produit->fresh()->load('seuilsAlerte');
        $this->assertSame(10, app(StockStatutService::class)->seuilEffectifPourSite($produit->fresh(), $this->cba->id));
        $this->assertFalse(app(StockStatutService::class)->alerteActivePourSite($produit->fresh(), $this->cba->id));
    }

    // ── isolation entre organisations ───────────────────────────────────────

    public function test_update_refuse_un_site_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteAutreOrg = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Site étranger', 'type' => 'depot', 'localisation' => 'Z']);
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $siteAutreOrg->id, 'actif' => true, 'seuil' => 1000],
            ]))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('produit_seuils_alerte', ['produit_id' => $produit->id, 'site_id' => $siteAutreOrg->id]);
    }

    // ── contrôle du stock avec le bon site_id et la bonne activation ────────

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
        ]);
        $variante = $produit->variantePrincipale()->first();

        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 1000,
        ]);
        // CBA n'a aucune configuration : jamais d'alerte, quel que soit son stock.

        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $this->matoto->id, 'qte_stock' => 900]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $this->cba->id, 'qte_stock' => 900]);

        $response = $this->actingAs($this->user)->get(route('produits.show', $produit));
        $props = $response->original->getData()['page']['props'];
        $stocksParSite = collect($props['produit']['stocks_par_site'])->keyBy('site_id');

        // Même quantité (900) sur les deux sites, mais un seul est en alerte : celui dont le
        // seuil spécifique (1000) dépasse la quantité — jamais le seuil de l'AUTRE site.
        $this->assertSame(1000, $stocksParSite[$this->matoto->id]['seuil_effectif']);
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $stocksParSite[$this->matoto->id]['statut']);
        $this->assertSame(StockStatut::DISPONIBLE->value, $stocksParSite[$this->cba->id]['statut']);
    }

    public function test_un_site_desactive_ne_genere_jamais_dalerte_meme_avec_un_stock_tres_faible(): void
    {
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->firstOrFail();
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit non géré à Lambanyi',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $lambanyi = Site::create(['organization_id' => $this->org->id, 'nom' => 'Lambanyi', 'type' => 'depot', 'localisation' => 'Lambanyi']);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->cba->id, 'actif' => true, 'seuil' => 50],
                ['site_id' => $lambanyi->id, 'actif' => false, 'seuil' => null],
            ]));

        // Stock quasi nul (1 unité, très en dessous de tout seuil raisonnable) sur le site
        // désactivé : toujours aucune alerte "stock faible" (la rupture, elle, reste calculée
        // indépendamment — cf. STOCK-ALERTE-004 — mais ce n'est pas ce qui est testé ici).
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $lambanyi->id, 'qte_stock' => 1]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $this->cba->id, 'qte_stock' => 1]);

        $response = $this->actingAs($this->user)->get(route('produits.show', $produit));
        $props = $response->original->getData()['page']['props'];
        $stocksParSite = collect($props['produit']['stocks_par_site'])->keyBy('site_id');

        $this->assertSame(StockStatut::DISPONIBLE->value, $stocksParSite[$lambanyi->id]['statut']);
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $stocksParSite[$this->cba->id]['statut']);
    }

    public function test_la_configuration_par_site_reste_appliquee_apres_une_nouvelle_modification_du_produit(): void
    {
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $this->cba->id, 'actif' => true, 'seuil' => 42],
            ]));

        // Une modification ultérieure qui ne touche pas seuils_site (champ absent du payload) ne
        // doit rien altérer à la configuration déjà enregistrée.
        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => 'Produit test — renommé',
                'produit_type_id' => $produit->produit_type_id,
                'statut' => 'actif',
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $produit->id,
            'site_id' => $this->cba->id,
            'actif' => true,
            'seuil_alerte_stock' => 42,
        ]);
    }
}
