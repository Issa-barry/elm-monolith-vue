<?php

namespace Tests\Unit;

use App\Enums\StockStatut;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitSeuilAlerte;
use App\Models\ProduitType;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\ProduitService;
use App\Services\StockStatutService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre la règle centrale du chantier "alerte stock" (revue le 29/08/2026 — seuil par SITE) :
 * le seuil vient du couple (PRODUIT, SITE), la quantité et l'état vivent au niveau
 * VARIANTE × SITE — un stock confortable ailleurs (autre variante, autre site, total
 * organisation) ne doit jamais masquer une alerte locale, et le seuil d'un site ne doit jamais
 * s'appliquer à un autre site.
 */
class StockStatutServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockStatutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockStatutService::class);
    }

    // ── statutPour() : la règle PURE — ne connaît ni l'alerte ni la disponibilité ─
    // Décision du 02/09/2026 après-midi (en remplacement d'une confusion introduite puis
    // corrigée le jour même) : le stock physique reste réel, toujours. Ce n'est JAMAIS
    // statutPour() qui décide si un état doit être notifié/compté — cf. disponiblePourSite()/
    // alerteActivePourSite() ci-dessous, appliquées séparément par les appelants.

    public function test_qte_zero_est_toujours_une_rupture(): void
    {
        $this->assertSame(StockStatut::RUPTURE, $this->service->statutPour(0, 10));
    }

    /**
     * Depuis le correctif du 23/08/2026 (politique globale "Autoriser les ventes sans stock
     * disponible"), une quantité strictement négative est un état distinct de RUPTURE — jamais
     * la même valeur affichée artificiellement comme 0 (cf. MouvementStockService::appliquer(),
     * qui n'applique plus de clamp).
     */
    public function test_qte_negative_est_toujours_stock_negatif_jamais_rupture(): void
    {
        $this->assertSame(StockStatut::STOCK_NEGATIF, $this->service->statutPour(-1, 10));
        $this->assertNotSame(StockStatut::RUPTURE, $this->service->statutPour(-1, 10));
    }

    public function test_stock_faible_des_que_la_quantite_est_sous_le_seuil(): void
    {
        $this->assertSame(StockStatut::STOCK_FAIBLE, $this->service->statutPour(5, 10));
    }

    public function test_qte_egale_au_seuil_est_stock_faible(): void
    {
        $this->assertSame(StockStatut::STOCK_FAIBLE, $this->service->statutPour(10, 10));
    }

    public function test_qte_strictement_superieure_au_seuil_est_disponible(): void
    {
        $this->assertSame(StockStatut::DISPONIBLE, $this->service->statutPour(11, 10));
    }

    public function test_seuil_zero_desactive_le_stock_faible(): void
    {
        $this->assertSame(StockStatut::DISPONIBLE, $this->service->statutPour(5, 0));
    }

    // ── seuilEffectifPourSite() : seuil du SITE, repli sur le seuil global ──────

    public function test_seuil_effectif_pour_site_utilise_le_seuil_specifique_de_ce_site_si_defini(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Matoto']);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit test',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);
        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'seuil_alerte_stock' => 5,
        ]);

        $this->assertSame(5, $this->service->seuilEffectifPourSite($produit, $site->id));
    }

    private function setSeuilGlobal(string $orgId, int $valeur): void
    {
        // Parametre::set() ne fait qu'un UPDATE (aucune ligne par défaut pour une organisation
        // fraîchement créée via factory, contrairement à ParametreSeeder en conditions réelles).
        Parametre::create([
            'organization_id' => $orgId,
            'cle' => Parametre::CLE_SEUIL_STOCK_FAIBLE,
            'valeur' => (string) $valeur,
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_GENERAL,
        ]);
    }

    public function test_seuil_effectif_pour_site_replie_sur_le_seuil_global_si_aucun_specifique(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Sonfonia', 'type' => 'depot', 'localisation' => 'Sonfonia']);
        $this->setSeuilGlobal($org->id, 20);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit sans seuil',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);

        $this->assertSame(20, $this->service->seuilEffectifPourSite($produit, $site->id));
    }

    public function test_seuil_specifique_dun_site_ne_saplique_jamais_a_un_autre_site(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $this->setSeuilGlobal($org->id, 10);
        $matoto = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Matoto']);
        $cba = Site::create(['organization_id' => $org->id, 'nom' => 'CBA', 'type' => 'depot', 'localisation' => 'CBA']);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit multi-sites',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);
        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $matoto->id,
            'seuil_alerte_stock' => 1000,
        ]);

        $this->assertSame(1000, $this->service->seuilEffectifPourSite($produit, $matoto->id));
        // CBA n'a aucun seuil spécifique : repli sur le seuil global, jamais celui de Matoto.
        $this->assertSame(10, $this->service->seuilEffectifPourSite($produit, $cba->id));
    }

    // ── alerteActivePourSite() : activation par SITE, jamais implicite ──────────

    public function test_alerte_active_pour_site_est_fausse_en_absence_de_ligne(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Lambanyi', 'type' => 'depot', 'localisation' => 'Lambanyi']);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit non concerné par ce site',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);

        // Aucune ligne produit_seuils_alerte pour ce site : jamais actif par défaut, même si un
        // seuil spécifique existe sur un AUTRE site du même produit (cf. test suivant).
        $this->assertFalse($this->service->alerteActivePourSite($produit, $site->id));
    }

    public function test_alerte_active_pour_site_est_vraie_quand_explicitement_activee(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $cba = Site::create(['organization_id' => $org->id, 'nom' => 'CBA', 'type' => 'depot', 'localisation' => 'CBA']);
        $lambanyi = Site::create(['organization_id' => $org->id, 'nom' => 'Lambanyi', 'type' => 'depot', 'localisation' => 'Lambanyi']);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit multi-sites',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);
        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $cba->id,
            'actif' => true,
            'seuil_alerte_stock' => 50,
        ]);

        $this->assertTrue($this->service->alerteActivePourSite($produit, $cba->id));
        // Lambanyi n'a aucune ligne : jamais actif par ricochet parce que CBA l'est.
        $this->assertFalse($this->service->alerteActivePourSite($produit, $lambanyi->id));
    }

    public function test_alerte_active_pour_site_est_fausse_pour_une_ligne_explicitement_desactivee(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Gomboyah', 'type' => 'depot', 'localisation' => 'Gomboyah']);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit désactivé sur ce site',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);
        // Un seuil spécifique existe (configuré puis désactivé) mais actif=false : reste inactif,
        // même avec une ligne présente en base (cf. ProduitSeuilAlerteService::definir()).
        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $site->id,
            'actif' => false,
            'seuil_alerte_stock' => 20,
        ]);

        $this->assertFalse($this->service->alerteActivePourSite($produit, $site->id));
    }

    // ── disponiblePourSite() : disponibilité par SITE, défaut VRAI ──────────────
    // Notion INDÉPENDANTE de alerteActivePourSite() (cf. StockStatutService, décision du
    // 02/09/2026 après-midi) : à l'inverse de l'alerte, l'absence de ligne signifie DISPONIBLE
    // (mode "Tous les sites" par défaut), jamais indisponible par défaut.

    public function test_disponible_pour_site_est_vraie_en_absence_de_ligne(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Sonfonia', 'type' => 'depot', 'localisation' => 'Sonfonia']);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit jamais configuré',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);

        // Aucune ligne produit_seuils_alerte pour ce site : disponible par défaut (mode "Tous
        // les sites"), à l'inverse de alerteActivePourSite() qui défaut à faux.
        $this->assertTrue($this->service->disponiblePourSite($produit, $site->id));
    }

    public function test_disponible_pour_site_est_fausse_quand_explicitement_restreinte(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $cba = Site::create(['organization_id' => $org->id, 'nom' => 'CBA', 'type' => 'depot', 'localisation' => 'CBA']);
        $cimenterie = Site::create(['organization_id' => $org->id, 'nom' => 'Cimenterie', 'type' => 'depot', 'localisation' => 'Cimenterie']);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Pack 25 sachets',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
        ]);
        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $cimenterie->id,
            'disponible' => false,
        ]);

        $this->assertFalse($this->service->disponiblePourSite($produit, $cimenterie->id));
        // CBA n'a aucune ligne : reste disponible, jamais affecté par la restriction de Cimenterie.
        $this->assertTrue($this->service->disponiblePourSite($produit, $cba->id));
    }

    public function test_deux_organisations_ont_des_seuils_globaux_independants(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($orgA->id);
        ProduitTypeDefaultSeeder::seedPourOrganisation($orgB->id);
        $this->setSeuilGlobal($orgA->id, 5);
        $this->setSeuilGlobal($orgB->id, 50);

        $typeA = ProduitType::where('organization_id', $orgA->id)->where('code', 'materiel')->first();
        $typeB = ProduitType::where('organization_id', $orgB->id)->where('code', 'materiel')->first();
        $siteA = Site::create(['organization_id' => $orgA->id, 'nom' => 'Site A', 'type' => 'depot', 'localisation' => 'A']);
        $siteB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'B']);

        $produitA = Produit::create(['organization_id' => $orgA->id, 'nom' => 'A', 'produit_type_id' => $typeA->id, 'statut' => 'actif']);
        $produitB = Produit::create(['organization_id' => $orgB->id, 'nom' => 'B', 'produit_type_id' => $typeB->id, 'statut' => 'actif']);

        $this->assertSame(5, $this->service->seuilEffectifPourSite($produitA, $siteA->id));
        $this->assertSame(50, $this->service->seuilEffectifPourSite($produitB, $siteB->id));
    }

    // ── compterAlertesPourOrganisation() : badge sidebar, seuil résolu par site ─

    public function test_compter_alertes_pour_organisation_resout_le_seuil_du_bon_site(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'achat_vente')->firstOrFail();
        $matoto = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Matoto']);
        $cba = Site::create(['organization_id' => $org->id, 'nom' => 'CBA', 'type' => 'depot', 'localisation' => 'CBA']);

        $produit = app(ProduitService::class)->creer([
            'organization_id' => $org->id,
            'nom' => 'Eau minérale',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $variante = $produit->variantePrincipale()->first();

        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 1000,
        ]);
        // CBA n'a aucun seuil spécifique : repli sur le seuil global (10 par défaut).

        // Même quantité (900) sur les deux sites, mais un seul doit compter comme "faible" :
        // celui dont le seuil SPÉCIFIQUE (1000) dépasse la quantité — jamais celui de l'autre
        // site (10, qui ne déclencherait rien pour 900).
        VarianteStock::create(['organization_id' => $org->id, 'produit_variante_id' => $variante->id, 'site_id' => $matoto->id, 'qte_stock' => 900]);
        VarianteStock::create(['organization_id' => $org->id, 'produit_variante_id' => $variante->id, 'site_id' => $cba->id, 'qte_stock' => 900]);

        $resultat = $this->service->compterAlertesPourOrganisation($org->id);

        $this->assertSame(0, $resultat['ruptures']);
        $this->assertSame(1, $resultat['faibles']);
        $this->assertSame(1, $resultat['total']);
    }

    /**
     * Régression 02/09/2026 (signalée en production) : le compteur sidebar comptait une rupture
     * réelle (quantité 0) même sur un site sans alerte active pour ce produit — la rupture était
     * comptée indépendamment de l'activation, en dur dans la requête SQL de cette méthode.
     */
    public function test_compter_alertes_pour_organisation_ignore_un_site_hors_du_systeme_dalerte(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'achat_vente')->firstOrFail();
        $cimenterie = Site::create(['organization_id' => $org->id, 'nom' => 'Cimenterie', 'type' => 'depot', 'localisation' => 'Cimenterie']);

        $produit = app(ProduitService::class)->creer([
            'organization_id' => $org->id,
            'nom' => 'Pack Bouteille de 1500ml',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $variante = $produit->variantePrincipale()->first();

        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $cimenterie->id,
            'actif' => false,
            'seuil_alerte_stock' => 666,
        ]);

        VarianteStock::create(['organization_id' => $org->id, 'produit_variante_id' => $variante->id, 'site_id' => $cimenterie->id, 'qte_stock' => 0]);

        $resultat = $this->service->compterAlertesPourOrganisation($org->id);

        $this->assertSame(0, $resultat['ruptures']);
        $this->assertSame(0, $resultat['faibles']);
        $this->assertSame(0, $resultat['total']);
    }

    /**
     * Garde INDÉPENDANTE de la précédente : un site DISPONIBLE=faux n'est jamais compté même
     * avec l'alerte active et une rupture réelle — cf. décision du 02/09/2026 (disponibilité et
     * alerte sont deux filtres distincts, tous deux requis).
     */
    public function test_compter_alertes_pour_organisation_ignore_un_site_non_disponible_meme_avec_alerte_active(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'achat_vente')->firstOrFail();
        $cimenterie = Site::create(['organization_id' => $org->id, 'nom' => 'Cimenterie', 'type' => 'depot', 'localisation' => 'Cimenterie']);

        $produit = app(ProduitService::class)->creer([
            'organization_id' => $org->id,
            'nom' => 'Pack 25 sachets',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $variante = $produit->variantePrincipale()->first();

        ProduitSeuilAlerte::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'site_id' => $cimenterie->id,
            'disponible' => false,
            'actif' => true,
            'seuil_alerte_stock' => 10,
        ]);

        VarianteStock::create(['organization_id' => $org->id, 'produit_variante_id' => $variante->id, 'site_id' => $cimenterie->id, 'qte_stock' => 0]);

        $resultat = $this->service->compterAlertesPourOrganisation($org->id);

        $this->assertSame(0, $resultat['ruptures']);
        $this->assertSame(0, $resultat['total']);
    }
}
