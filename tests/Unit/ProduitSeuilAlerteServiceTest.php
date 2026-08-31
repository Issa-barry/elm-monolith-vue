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
 * Couvre ProduitSeuilAlerteService — gestion des seuils d'alerte de stock spécifiques par
 * COUPLE (produit, site), qui remplace l'ancien seuil unique produits.seuil_alerte_stock.
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
            'alerte_stock_active' => true,
        ]);
    }

    public function test_definir_cree_un_seuil_specifique_pour_matoto(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, 1000);

        $this->assertDatabaseHas('produit_seuils_alerte', [
            'produit_id' => $this->produit->id,
            'site_id' => $this->matoto->id,
            'seuil_alerte_stock' => 1000,
        ]);
    }

    public function test_definir_accepte_un_seuil_different_pour_cba(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, 1000);
        $this->service->definir($this->produit, $this->cba->id, 300);

        $this->assertSame(1000, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->matoto->id)->value('seuil_alerte_stock'));
        $this->assertSame(300, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->cba->id)->value('seuil_alerte_stock'));
    }

    public function test_pour_produit_replie_sur_aucune_ligne_quand_aucun_seuil_specifique(): void
    {
        $this->assertTrue($this->service->pourProduit($this->produit)->isEmpty());
    }

    public function test_definir_avec_null_supprime_le_seuil_specifique(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, 1000);
        $this->assertDatabaseHas('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->matoto->id]);

        $this->service->definir($this->produit, $this->matoto->id, null);

        $this->assertDatabaseMissing('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $this->matoto->id]);
    }

    public function test_definir_modifie_un_seuil_existant_sans_dupliquer_la_ligne(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, 500);
        $this->service->definir($this->produit, $this->matoto->id, 800);

        $this->assertSame(1, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->matoto->id)->count());
        $this->assertSame(800, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->matoto->id)->value('seuil_alerte_stock'));
    }

    public function test_definir_pour_tous_les_sites_actifs_applique_la_meme_valeur_partout(): void
    {
        $this->service->definirPourTousLesSitesActifs($this->produit, 250);

        $this->assertSame(250, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->matoto->id)->value('seuil_alerte_stock'));
        $this->assertSame(250, ProduitSeuilAlerte::where('produit_id', $this->produit->id)->where('site_id', $this->cba->id)->value('seuil_alerte_stock'));
    }

    public function test_definir_pour_tous_les_sites_actifs_ignore_les_sites_inactifs(): void
    {
        $inactif = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site fermé', 'type' => 'depot', 'localisation' => 'X', 'statut' => 'inactive']);

        $this->service->definirPourTousLesSitesActifs($this->produit, 250);

        $this->assertDatabaseMissing('produit_seuils_alerte', ['produit_id' => $this->produit->id, 'site_id' => $inactif->id]);
    }

    public function test_valeur_uniforme_retourne_la_valeur_si_tous_les_sites_actifs_correspondent(): void
    {
        $this->service->definirPourTousLesSitesActifs($this->produit, 250);

        $this->assertSame(250, $this->service->valeurUniformePourSitesActifs($this->produit));
    }

    public function test_valeur_uniforme_retourne_null_si_les_seuils_sont_mixtes(): void
    {
        $this->service->definir($this->produit, $this->matoto->id, 1000);
        $this->service->definir($this->produit, $this->cba->id, 300);

        $this->assertNull($this->service->valeurUniformePourSitesActifs($this->produit));
    }

    public function test_valeur_uniforme_retourne_null_si_aucun_seuil_specifique(): void
    {
        $this->assertNull($this->service->valeurUniformePourSitesActifs($this->produit));
    }

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
            'alerte_stock_active' => true,
        ]);

        $this->service->definir($this->produit, $this->matoto->id, 1000);
        $this->service->definir($autreProduit, $autreSite->id, 42);

        $this->assertSame(42, $this->service->pourProduit($autreProduit)->get($autreSite->id));
        $this->assertTrue($this->service->pourProduit($this->produit)->get($autreSite->id) === null);
        $this->assertCount(1, $this->service->pourProduit($this->produit));
    }
}
