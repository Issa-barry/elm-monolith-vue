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
 * Fiche produit > Disponibilité et Alerte de stock faible par AGENCE — deux notions
 * INDÉPENDANTES (chantier 29/08/2026 pour le seuil, étendu le 01/09/2026 à l'activation, puis
 * scindé le 02/09/2026 après-midi entre DISPONIBILITÉ et ALERTE, cf. StockStatutService) :
 *   - Disponibilité ("ce produit est-il vendu sur ce site ?") — défaut VRAI partout ;
 *   - Alerte ("faut-il notifier ?") — défaut FAUX, avec seuil par site.
 * Un site non disponible n'a jamais de rupture "métier" ; un site disponible mais sans alerte
 * affiche son état réel sans jamais notifier. Couvre le formulaire web
 * (ProduitController::edit()/update()) et le contrôle du stock avec le bon site_id.
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

    public function test_edit_expose_la_disponibilite_deja_enregistree(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'disponible' => false,
        ]);

        $response = $this->actingAs($this->user)->get(route('produits.edit', $produit));
        $props = $response->original->getData()['page']['props'];

        $this->assertFalse($props['seuilsAlerteSite'][$this->matoto->id]['disponible']);
        // CBA n'a aucune ligne : disponible par défaut, absent de la collection.
        $this->assertArrayNotHasKey((string) $this->cba->id, $props['seuilsAlerteSite']);
    }

    // ── update() : disponibilité, indépendante de l'alerte ───────────────────

    public function test_update_mode_selection_restreint_le_produit_aux_sites_coches(): void
    {
        $lambanyi = Site::create(['organization_id' => $this->org->id, 'nom' => 'Lambanyi', 'type' => 'depot', 'localisation' => 'Lambanyi']);
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), array_merge(
                $this->updatePayload($produit, []),
                ['disponibilite_mode' => 'selection', 'sites_disponibles' => [$this->cba->id]],
            ))
            ->assertSessionDoesntHaveErrors();

        $service = app(StockStatutService::class);
        $produit = $produit->fresh()->load('seuilsAlerte');
        $this->assertTrue($service->disponiblePourSite($produit, $this->cba->id));
        $this->assertFalse($service->disponiblePourSite($produit, $this->matoto->id));
        $this->assertFalse($service->disponiblePourSite($produit, $lambanyi->id));
    }

    public function test_update_mode_tous_leve_toute_restriction_de_disponibilite(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'disponible' => false,
        ]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), array_merge(
                $this->updatePayload($produit, []),
                ['disponibilite_mode' => 'tous'],
            ));

        $this->assertTrue(app(StockStatutService::class)->disponiblePourSite($produit->fresh()->load('seuilsAlerte'), $this->matoto->id));
    }

    public function test_update_sans_disponibilite_mode_ne_touche_pas_la_disponibilite_existante(): void
    {
        $produit = $this->makeProduit();
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'site_id' => $this->matoto->id,
            'disponible' => false,
        ]);

        // Payload SANS disponibilite_mode (champ absent) : une modification qui ne touche pas
        // cette section ne doit rien altérer.
        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, []));

        $this->assertFalse(app(StockStatutService::class)->disponiblePourSite($produit->fresh()->load('seuilsAlerte'), $this->matoto->id));
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

    /**
     * Décision produit du 02/09/2026 après-midi (en remplacement d'une confusion introduite puis
     * corrigée le jour même) : désactiver l'ALERTE d'un site DISPONIBLE ne doit JAMAIS masquer
     * son état physique réel — "le stock physique reste réel". Seules les notifications/badges
     * d'alerte sont supprimés ; la page Stock / fiche produit continuent d'afficher l'état réel.
     */
    public function test_alerte_desactivee_naffiche_jamais_de_badge_mais_le_stock_reel_reste_visible(): void
    {
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->firstOrFail();
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit non surveillé à Lambanyi',
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

        // Stock quasi nul (1 unité) sur les deux sites — Lambanyi reste DISPONIBLE (jamais
        // restreint), seule son alerte est désactivée.
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $lambanyi->id, 'qte_stock' => 1]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $this->cba->id, 'qte_stock' => 1]);

        $response = $this->actingAs($this->user)->get(route('produits.show', $produit));
        $props = $response->original->getData()['page']['props'];
        $stocksParSite = collect($props['produit']['stocks_par_site'])->keyBy('site_id');

        // Stock réel affiché des DEUX côtés (1 <= seuil de 10/50 → Stock faible) — Lambanyi
        // n'est PAS forcé à "Disponible" simplement parce que son alerte est coupée.
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $stocksParSite[$lambanyi->id]['statut']);
        $this->assertTrue($stocksParSite[$lambanyi->id]['disponible_sur_site']);
        $this->assertFalse($stocksParSite[$lambanyi->id]['alerte_active']);
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $stocksParSite[$this->cba->id]['statut']);
        // Le badge d'en-tête "Stock faible" reste vrai grâce à CBA (alerte active) — Lambanyi
        // n'y contribue jamais (son alerte est désactivée), mais ne l'invalide pas non plus.
        $this->assertTrue($props['produit']['is_low_stock']);
    }

    /**
     * Régression 02/09/2026 (signalée en production) : un produit en rupture totale (0 unité)
     * sur un site dont l'alerte est désactivée déclenchait quand même un email — corrigé en
     * conditionnant la notification à l'alerte ET à la disponibilité (jamais à un statut
     * artificiellement forcé à Disponible, cf. test précédent).
     */
    public function test_alerte_desactivee_naffiche_jamais_de_badge_meme_a_quantite_nulle(): void
    {
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->firstOrFail();
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Pack Bouteille de 1500ml',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $cimenterie = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cimenterie', 'type' => 'depot', 'localisation' => 'Cimenterie']);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), $this->updatePayload($produit, [
                ['site_id' => $cimenterie->id, 'actif' => false, 'seuil' => null],
            ]));

        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $cimenterie->id, 'qte_stock' => 0]);

        $response = $this->actingAs($this->user)->get(route('produits.show', $produit));
        $props = $response->original->getData()['page']['props'];
        $stocksParSite = collect($props['produit']['stocks_par_site'])->keyBy('site_id');

        // Cimenterie n'a jamais été restreinte en disponibilité : son état réel (Rupture) reste
        // affiché sur la page — seul le badge d'en-tête (alerte) est supprimé, faute d'alerte
        // active pour ce site.
        $this->assertSame(StockStatut::RUPTURE->value, $stocksParSite[$cimenterie->id]['statut']);
        $this->assertTrue($stocksParSite[$cimenterie->id]['disponible_sur_site']);
        $this->assertFalse($props['produit']['is_out_of_stock']);
    }

    /**
     * Un site marqué NON DISPONIBLE (cf. section "Disponibilité" du formulaire) n'a, lui,
     * jamais de rupture "métier" à afficher, quel que soit son stock physique — distinct du test
     * précédent, où le site reste disponible mais sans alerte.
     */
    public function test_un_site_non_disponible_naffiche_jamais_rupture_meme_a_quantite_nulle(): void
    {
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->firstOrFail();
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Pack Bouteille de 1500ml',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $cimenterie = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cimenterie', 'type' => 'depot', 'localisation' => 'Cimenterie']);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), array_merge(
                $this->updatePayload($produit, []),
                ['disponibilite_mode' => 'selection', 'sites_disponibles' => [$this->cba->id, $this->matoto->id]],
            ));

        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $cimenterie->id, 'qte_stock' => 0]);

        $response = $this->actingAs($this->user)->get(route('produits.show', $produit));
        $props = $response->original->getData()['page']['props'];
        $stocksParSite = collect($props['produit']['stocks_par_site'])->keyBy('site_id');

        // Cimenterie non disponible : le frontend doit afficher "Non disponible" (pas le statut
        // coloré) — is_out_of_stock reste faux, aucune notification possible.
        $this->assertFalse($stocksParSite[$cimenterie->id]['disponible_sur_site']);
        $this->assertFalse($props['produit']['is_out_of_stock']);
    }

    /**
     * Même scénario que le test précédent, mais sur la liste Produits (bannière "Rupture de
     * stock" + badge par ligne) — ProduitController::index() calculait auparavant is_out_of_stock
     * via un raccourci sur la seule quantité brute (`$s->qte_stock <= 0`), sans jamais consulter
     * la disponibilité par site.
     */
    public function test_la_liste_produits_naffiche_pas_rupture_pour_un_site_non_disponible(): void
    {
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->firstOrFail();
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Pack Bouteille de 1500ml',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $cimenterie = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cimenterie', 'type' => 'depot', 'localisation' => 'Cimenterie']);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), array_merge(
                $this->updatePayload($produit, []),
                ['disponibilite_mode' => 'selection', 'sites_disponibles' => [$this->cba->id, $this->matoto->id]],
            ));

        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $variante->id, 'site_id' => $cimenterie->id, 'qte_stock' => 0]);

        $response = $this->actingAs($this->user)->get(route('produits.index'));
        $props = $response->original->getData()['page']['props'];
        $ligne = collect($props['produits'])->firstWhere('id', $produit->id);

        $this->assertFalse($ligne['is_out_of_stock']);
        $this->assertTrue($ligne['in_stock']);
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
