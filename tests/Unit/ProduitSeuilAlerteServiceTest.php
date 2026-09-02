<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitSeuilAlerte;
use App\Models\ProduitType;
use App\Models\Site;
use App\Services\ProduitSeuilAlerteService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre ProduitSeuilAlerteService — gestion de l'activation ET du seuil d'alerte de stock
 * spécifiques par COUPLE (produit, site), qui remplace l'ancien choix global
 * produits.alerte_stock_active et l'ancien seuil unique produits.seuil_alerte_stock.
 */
class ProduitSeuilAlerteServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProduitSeuilAlerteService $service;

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProduitSeuilAlerteService::class);
        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'materiel')->firstOrFail();

        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Matoto']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot', 'localisation' => 'CBA']);

        $this->produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit test',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);
    }

    // ── definir() : activation + seuil ───────────────────────────────────────

    public function test_definir_active_matoto_avec_un_seuil_specifique(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, true, 1000);

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $this->produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 1000,
        ]);
    }

    public function test_definir_accepte_un_seuil_different_pour_cba(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, true, 1000);
        $this->service->definir($this->produit, $this->cba->id, true, 300);

        $this->assertSame(1000, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->matoto->id)->value('seuil_alerte_stock'));
        $this->assertSame(300, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->cba->id)->value('seuil_alerte_stock'));
    }

    public function test_pour_produit_est_vide_quand_aucun_site_configure(): void
    {
        $this->assertTrue($this->service->pourProduit($this->produit)->isEmpty());
    }

    public function test_definir_active_avec_seuil_null_replie_sur_le_defaut_sans_erreur(): void
    {
        // Décision produit du 01/09/2026 : un seuil vide sur un site activé n'est jamais
        // bloquant, il signifie « utiliser le seuil par défaut de l'organisation ».
        $this->service->definir($this->produit, $this->matoto->id, true, null);

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $this->produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => null,
        ]);
    }

    public function test_definir_desactive_ne_supprime_pas_le_seuil_specifique_deja_enregistre(): void
    {
        // Désactiver un site ne doit jamais faire perdre un seuil déjà configuré : la ligne
        // reste en base (actif=false), prête à être réactivée sans ressaisie.
        $this->service->definir($this->produit, $this->matoto->id, true, 1000);

        $this->service->definir($this->produit, $this->matoto->id, false, null);

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $this->produit->id,
            'site_id' => $this->matoto->id,
            'actif' => false,
            'seuil_alerte_stock' => 1000,
        ]);
        $this->assertFalse($this->service->pourProduit($this->produit)->get($this->matoto->id)['actif']);
    }

    public function test_definir_desactive_un_site_jamais_configure_najoute_aucune_ligne(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, false, null);

        $this->assertDatabaseMissing('produit_seuils_alerte', [
            'produit_id' => $this->produit->id,
            'site_id' => $this->matoto->id,
        ]);
    }

    public function test_definir_modifie_un_seuil_existant_sans_dupliquer_la_ligne(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, true, 500);
        $this->service->definir($this->produit, $this->matoto->id, true, 800);

        $this->assertSame(1, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->matoto->id)->count());
        $this->assertSame(800, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->matoto->id)->value('seuil_alerte_stock'));
    }

    // ── definirPourTousLesSitesActifs() : import en masse ────────────────────

    public function test_definir_pour_tous_les_sites_actifs_active_partout_avec_la_meme_valeur(): void
    {
        $this->service->definirPourTousLesSitesActifs($this->produit, true, 250);

        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->matoto->id, 'actif' => true, 'seuil_alerte_stock' => 250]);
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->cba->id, 'actif' => true, 'seuil_alerte_stock' => 250]);
    }

    public function test_definir_pour_tous_les_sites_actifs_avec_false_desactive_sans_supprimer_les_seuils(): void
    {
        $this->service->definirPourTousLesSitesActifs($this->produit, true, 250);

        $this->service->definirPourTousLesSitesActifs($this->produit, false, null);

        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->matoto->id, 'actif' => false, 'seuil_alerte_stock' => 250]);
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->cba->id, 'actif' => false, 'seuil_alerte_stock' => 250]);
    }

    public function test_definir_pour_tous_les_sites_actifs_ignore_les_sites_inactifs(): void
    {
        $inactif = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site fermé', 'type' => 'depot', 'localisation' => 'X', 'statut' => 'inactive']);

        $this->service->definirPourTousLesSitesActifs($this->produit, true, 250);

        $this->assertDatabaseMissing('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $inactif->id]);
    }

    // ── activerPourTousLesSitesActifs() : bascule l'activation sans toucher au seuil ──

    public function test_activer_pour_tous_les_sites_actifs_preserve_les_seuils_deja_enregistres(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, true, 1000);
        $this->service->definir($this->produit, $this->cba->id, true, 300);

        $this->service->activerPourTousLesSitesActifs($this->produit, false);

        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->matoto->id, 'actif' => false, 'seuil_alerte_stock' => 1000]);
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->cba->id, 'actif' => false, 'seuil_alerte_stock' => 300]);
    }

    // ── definirSeuilSeulPourTousLesSitesActifs() : ne crée jamais d'activation implicite ──

    public function test_definir_seuil_seul_ne_touche_pas_lactivation_ni_ne_cree_de_ligne(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, true, 100);
        // CBA n'a jamais été configuré : un seuil seul ne doit jamais l'activer.

        $this->service->definirSeuilSeulPourTousLesSitesActifs($this->produit, 500);

        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->matoto->id, 'actif' => true, 'seuil_alerte_stock' => 500]);
        $this->assertDatabaseMissing('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->cba->id]);
    }

    // ── valeurUniformePourSitesActifs() : audit d'import uniquement ──────────

    public function test_valeur_uniforme_retourne_la_valeur_si_tous_les_sites_actifs_correspondent(): void
    {
        $this->service->definirPourTousLesSitesActifs($this->produit, true, 250);

        $this->assertSame(250, $this->service->valeurUniformePourSitesActifs($this->produit));
    }

    public function test_valeur_uniforme_retourne_null_si_les_seuils_sont_mixtes(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, true, 1000);
        $this->service->definir($this->produit, $this->cba->id, true, 300);

        $this->assertNull($this->service->valeurUniformePourSitesActifs($this->produit));
    }

    public function test_valeur_uniforme_retourne_null_si_aucun_seuil_specifique(): void
    {
        $this->assertNull($this->service->valeurUniformePourSitesActifs($this->produit));
    }

    public function test_valeur_uniforme_ignore_un_site_desactive(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, true, 250);
        $this->service->definir($this->produit, $this->cba->id, false, null);

        // CBA n'est pas actif : ne compte pas comme partageant la valeur 250.
        $this->assertNull($this->service->valeurUniformePourSitesActifs($this->produit));
    }

    // ── isolation entre organisations ───────────────────────────────────────

    public function test_isolation_entre_organisations_un_seuil_dune_organisation_najoute_rien_a_lautre(): void
    {
        $autreOrg = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($autreOrg->id);
        $autreType = ProduitType::where('organization_id', $autreOrg->id)->where('code', 'materiel')->firstOrFail();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre site', 'type' => 'depot', 'localisation' => 'Y']);
        $autreProduit = Produit::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Produit autre organisation',
            'produit_type_id' => $autreType->id,
            'statut' => 'actif',
        ]);

        $this->service->definir($this->produit, $this->matoto->id, true, 1000);
        $this->service->definir($autreProduit, $autreSite->id, true, 42);

        $this->assertSame(42, $this->service->pourProduit($autreProduit)->get($autreSite->id)['seuil']);
        $this->assertNull($this->service->pourProduit($this->produit)->get($autreSite->id));
        $this->assertCount(1, $this->service->pourProduit($this->produit));
    }
}
