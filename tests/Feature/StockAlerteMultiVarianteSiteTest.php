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
use App\Services\ProduitSeuilAlerteService;
use App\Services\StockStatutService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Scénario de référence de tout le chantier "alerte stock" (cf. exemple "Air Force" de la
 * conversation de conception), revu le 29/08/2026 pour le seuil PAR SITE :
 *
 *   Produit : Air Force — seuil spécifique 15 à Matoto, aucun seuil spécifique à Sonfonia
 *   (repli sur le seuil global de l'organisation, 10 par défaut).
 *
 *   Matoto (seuil 15)   : Blanche = 20 (disponible)  |  Noire = 15 (stock faible)
 *   Sonfonia (seuil 10) : Blanche = 8 (faible)        |  Noire = 30 (disponible)
 *
 * Règles vérifiées : (1) chaque site peut avoir un seuil différent pour le même produit ; (2) le
 * seuil d'un site ne s'applique jamais à un autre site ; (3) le seuil s'applique à CHAQUE couple
 * variante × site indépendamment — jamais un total agrégé (toutes variantes, tous sites, ou toute
 * l'organisation) ne doit masquer une alerte locale.
 */
class StockAlerteMultiVarianteSiteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $matoto;

    private Site $sonfonia;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $typeAchatVente = ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->firstOrFail();

        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Matoto']);
        $this->sonfonia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Sonfonia', 'type' => 'depot', 'localisation' => 'Sonfonia']);

        $this->produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Air Force',
            'produit_type_id' => $typeAchatVente->id,
            'statut' => 'actif',
            'prix_achat' => 200000,
            'prix_vente' => 350000,
            'options' => [
                ['nom' => 'Couleur', 'valeurs' => ['Blanche', 'Noire']],
            ],
        ]);

        // Alerte activée sur les DEUX sites (choix explicite indépendant par site, cf.
        // ProduitSeuilAlerteService) — seuil spécifique UNIQUEMENT à Matoto, Sonfonia reste sans
        // seuil spécifique donc replie sur le seuil global de l'organisation (10 par défaut, cf.
        // Parametre::getSeuilStockFaible()).
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $this->produit->id,
            'site_id' => $this->matoto->id,
            'actif' => true,
            'seuil_alerte_stock' => 15,
        ]);
        ProduitSeuilAlerte::create([
            'organization_id' => $this->org->id,
            'produit_id' => $this->produit->id,
            'site_id' => $this->sonfonia->id,
            'actif' => true,
            'seuil_alerte_stock' => null,
        ]);

        $blanche = $this->produit->variantes->firstWhere('libelle', 'Blanche');
        $noire = $this->produit->variantes->firstWhere('libelle', 'Noire');

        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $blanche->id, 'site_id' => $this->matoto->id, 'qte_stock' => 20]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $noire->id, 'site_id' => $this->matoto->id, 'qte_stock' => 15]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $blanche->id, 'site_id' => $this->sonfonia->id, 'qte_stock' => 8]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $noire->id, 'site_id' => $this->sonfonia->id, 'qte_stock' => 30]);
    }

    public function test_seuils_differents_par_site_donnent_des_etats_differents_pour_la_meme_variante(): void
    {
        $service = app(StockStatutService::class);
        $produit = $this->produit->fresh();

        $this->assertSame(15, $service->seuilEffectifPourSite($produit, $this->matoto->id));
        $this->assertSame(10, $service->seuilEffectifPourSite($produit, $this->sonfonia->id));

        $this->assertSame(StockStatut::DISPONIBLE, $service->statutPour(20, 15), 'Blanche @ Matoto (seuil 15)');
        $this->assertSame(StockStatut::STOCK_FAIBLE, $service->statutPour(15, 15), 'Noire @ Matoto (seuil 15)');
        $this->assertSame(StockStatut::STOCK_FAIBLE, $service->statutPour(8, 10), 'Blanche @ Sonfonia (seuil 10, défaut organisation)');
        $this->assertSame(StockStatut::DISPONIBLE, $service->statutPour(30, 10), 'Noire @ Sonfonia (seuil 10, défaut organisation)');
    }

    public function test_le_seuil_specifique_dun_site_ne_saplique_jamais_a_un_autre_site(): void
    {
        // Contrôle anti-régression direct sur le détail variante × site : Noire@Matoto (15
        // unités) n'est en alerte QUE parce que le seuil retenu est celui de Matoto (15, pile
        // égal) — avec le seuil global (10) elle apparaîtrait à tort "disponible".
        $detail = app(StockStatutService::class)
            ->detailParVarianteEtSite($this->produit->fresh(['produitType', 'variantes.stocks', 'seuilsAlerte']));

        $noireMatoto = $detail->first(fn (array $d) => $d['site_id'] === $this->matoto->id && $d['qte_stock'] === 15);
        $this->assertNotNull($noireMatoto);
        $this->assertSame(15, $noireMatoto['seuil_effectif']);
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $noireMatoto['statut']);

        $noireSonfonia = $detail->first(fn (array $d) => $d['site_id'] === $this->sonfonia->id && $d['qte_stock'] === 30);
        $this->assertNotNull($noireSonfonia);
        $this->assertSame(10, $noireSonfonia['seuil_effectif'], 'Sonfonia doit utiliser le seuil global, jamais celui de Matoto.');
    }

    public function test_le_total_organisation_eleve_ne_masque_aucune_des_deux_alertes_locales(): void
    {
        // Total = 20 + 15 + 8 + 30 = 73, largement au-dessus de tous les seuils — et pourtant,
        // deux couples variante × site précis restent en alerte.
        $total = VarianteStock::whereHas('variante', fn ($q) => $q->where('produit_id', $this->produit->id))->sum('qte_stock');
        $this->assertSame(73, $total);

        $detail = app(StockStatutService::class)->detailParVarianteEtSite($this->produit->fresh(['produitType', 'variantes.stocks', 'seuilsAlerte']));
        $enAlerte = $detail->filter(fn (array $d) => $d['statut'] !== StockStatut::DISPONIBLE->value);

        $this->assertCount(2, $enAlerte, 'Noire@Matoto et Blanche@Sonfonia doivent rester détectées malgré un total confortable.');
    }

    public function test_nombre_alertes_stock_compte_les_couples_variante_site_pas_les_variantes(): void
    {
        // Une même variante en alerte sur 2 sites compterait pour 2 (règle actée) — ici on a
        // 2 variantes différentes, chacune en alerte sur un site différent : 2 alertes.
        $nombre = app(StockStatutService::class)->nombreAlertesPourProduit($this->produit->fresh(['produitType', 'variantes.stocks', 'seuilsAlerte']));
        $this->assertSame(2, $nombre);
    }

    /**
     * Décision du 02/09/2026 après-midi : désactiver l'ALERTE d'un site ne change JAMAIS son
     * état physique réel (statutPour() est une fonction pure) — seul le drapeau alerte_active
     * exposé par detailParVarianteEtSite() bascule, pour que les appelants (notifications,
     * badges) sachent ne jamais compter ce site, sans jamais mentir sur le stock réel.
     */
    public function test_desactiver_lalerte_dun_site_ne_change_jamais_son_etat_physique_reel(): void
    {
        app(ProduitSeuilAlerteService::class)->definir($this->produit, $this->matoto->id, false, null);

        $service = app(StockStatutService::class);
        $produit = $this->produit->fresh(['produitType', 'variantes.stocks', 'seuilsAlerte']);
        $this->assertFalse($service->alerteActivePourSite($produit, $this->matoto->id));

        // statutPour() reste pur : la quantité/le seuil seuls décident, jamais l'alerte.
        $this->assertSame(StockStatut::STOCK_FAIBLE, $service->statutPour(10, 10));
        $this->assertSame(StockStatut::RUPTURE, $service->statutPour(0, 10));

        // Noire@Matoto (15 unités, seuil 15) : état réel Stock faible conservé, alerte_active
        // basculée à faux — jamais l'inverse.
        $detail = $service->detailParVarianteEtSite($produit);
        $noireMatoto = $detail->first(fn (array $d) => $d['site_id'] === $this->matoto->id && $d['qte_stock'] === 15);
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $noireMatoto['statut']);
        $this->assertTrue($noireMatoto['disponible_sur_site']);
        $this->assertFalse($noireMatoto['alerte_active']);

        // nombreAlertesPourProduit() (badge) ne compte plus ce site désactivé : 1 seule alerte
        // reste (Blanche@Sonfonia), pas 2.
        $this->assertSame(1, $service->nombreAlertesPourProduit($produit));
    }

    /**
     * Garde INDÉPENDANTE de la précédente : rendre Matoto NON DISPONIBLE (plutôt que juste sans
     * alerte) masque son état réel derrière "Non disponible" pour le frontend, sans jamais
     * altérer le calcul physique lui-même.
     */
    public function test_rendre_un_site_non_disponible_est_independant_de_lalerte(): void
    {
        app(ProduitSeuilAlerteService::class)->definirDisponibilite($this->produit, $this->matoto->id, false);

        $service = app(StockStatutService::class);
        $produit = $this->produit->fresh(['produitType', 'variantes.stocks', 'seuilsAlerte']);

        // Matoto reste avec son alerte active (definie dans setUp()) — seule la disponibilité
        // change, indépendamment.
        $this->assertTrue($service->alerteActivePourSite($produit, $this->matoto->id));
        $this->assertFalse($service->disponiblePourSite($produit, $this->matoto->id));

        $detail = $service->detailParVarianteEtSite($produit);
        $noireMatoto = $detail->first(fn (array $d) => $d['site_id'] === $this->matoto->id && $d['qte_stock'] === 15);
        $this->assertSame(StockStatut::STOCK_FAIBLE->value, $noireMatoto['statut']);
        $this->assertFalse($noireMatoto['disponible_sur_site']);

        // nombreAlertesPourProduit() (badge) ignore aussi ce site non disponible.
        $this->assertSame(1, $service->nombreAlertesPourProduit($produit));
    }

    public function test_produit_sans_stock_gere_najoute_jamais_dalerte(): void
    {
        $typeService = ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->firstOrFail();
        $produitService = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Prestation conseil',
            'produit_type_id' => $typeService->id,
            'statut' => 'actif',
        ]);
        // Même si l'alerte était activée sur ce site, un type sans stock ne peut jamais alerter
        // (cf. StockStatutService::statutPourVarianteStock(), court-circuité avant toute lecture
        // de la configuration d'alerte).
        app(ProduitSeuilAlerteService::class)->definir($produitService, $this->matoto->id, true, 5);

        $statut = app(StockStatutService::class)->statutPourVarianteStock(
            $produitService->fresh(['produitType']),
            new VarianteStock(['qte_stock' => 0, 'site_id' => $this->matoto->id]),
        );

        $this->assertSame(StockStatut::DISPONIBLE, $statut);
    }

    public function test_variantes_generees_avec_options_nont_plus_de_seuil_propre(): void
    {
        // Aucun seuil par variante n'existe : les deux variantes générées via options partagent
        // implicitement le même seuil, celui de leur site (cf. produit_seuils_alerte).
        $this->assertCount(2, $this->produit->variantes);
        $this->assertFalse(Schema::hasColumn('produit_variantes', 'seuil_alerte_stock'));
    }
}
