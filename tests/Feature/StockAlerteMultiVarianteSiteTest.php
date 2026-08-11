<?php

namespace Tests\Feature;

use App\Enums\StockStatut;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\ProduitService;
use App\Services\StockStatutService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Scénario de référence de tout le chantier "alerte stock" (cf. exemple "Air Force" de la
 * conversation de conception) :
 *
 *   Produit : Air Force — seuil = 10 (configuré une seule fois, au niveau PRODUIT)
 *
 *   Matoto : Blanche = 20 (disponible)  |  Noire = 10 (stock faible)
 *   Sonfonia : Blanche = 8 (faible)     |  Noire = 30 (disponible)
 *
 * Règle vérifiée : le seuil unique du produit s'applique à CHAQUE couple variante × site
 * indépendamment — jamais un total agrégé (toutes variantes, tous sites, ou toute
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
            'alerte_stock_active' => true,
            'seuil_alerte_stock' => 10,
            'options' => [
                ['nom' => 'Couleur', 'valeurs' => ['Blanche', 'Noire']],
            ],
        ]);

        $blanche = $this->produit->variantes->firstWhere('libelle', 'Blanche');
        $noire = $this->produit->variantes->firstWhere('libelle', 'Noire');

        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $blanche->id, 'site_id' => $this->matoto->id, 'qte_stock' => 20]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $noire->id, 'site_id' => $this->matoto->id, 'qte_stock' => 10]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $blanche->id, 'site_id' => $this->sonfonia->id, 'qte_stock' => 8]);
        VarianteStock::create(['organization_id' => $this->org->id, 'produit_variante_id' => $noire->id, 'site_id' => $this->sonfonia->id, 'qte_stock' => 30]);
    }

    public function test_meme_seuil_produit_donne_des_etats_differents_par_variante_et_par_site(): void
    {
        $service = app(StockStatutService::class);
        $seuil = $service->seuilEffectif($this->produit->fresh());
        $this->assertSame(10, $seuil);

        // Vérifie directement les 4 combinaisons via statutPour(), la règle pure — même seuil
        // (10) appliqué aux 4 quantités réelles du scénario Air Force.
        $this->assertSame(StockStatut::DISPONIBLE, $service->statutPour(20, $seuil, true), 'Blanche @ Matoto');
        $this->assertSame(StockStatut::STOCK_FAIBLE, $service->statutPour(10, $seuil, true), 'Noire @ Matoto');
        $this->assertSame(StockStatut::STOCK_FAIBLE, $service->statutPour(8, $seuil, true), 'Blanche @ Sonfonia');
        $this->assertSame(StockStatut::DISPONIBLE, $service->statutPour(30, $seuil, true), 'Noire @ Sonfonia');
    }

    public function test_le_total_organisation_eleve_ne_masque_aucune_des_deux_alertes_locales(): void
    {
        // Total = 20 + 10 + 8 + 30 = 68, largement au-dessus du seuil de 10 — et pourtant,
        // deux couples variante × site précis restent en alerte.
        $total = VarianteStock::whereHas('variante', fn ($q) => $q->where('produit_id', $this->produit->id))->sum('qte_stock');
        $this->assertSame(68, $total);

        $detail = app(StockStatutService::class)->detailParVarianteEtSite($this->produit->fresh(['produitType', 'variantes.stocks']));
        $enAlerte = $detail->filter(fn (array $d) => $d['statut'] !== StockStatut::DISPONIBLE->value);

        $this->assertCount(2, $enAlerte, 'Noire@Matoto et Blanche@Sonfonia doivent rester détectées malgré un total confortable.');
    }

    public function test_nombre_alertes_stock_compte_les_couples_variante_site_pas_les_variantes(): void
    {
        // Une même variante en alerte sur 2 sites compterait pour 2 (règle actée) — ici on a
        // 2 variantes différentes, chacune en alerte sur un site différent : 2 alertes.
        $nombre = app(StockStatutService::class)->nombreAlertesPourProduit($this->produit->fresh(['produitType', 'variantes.stocks']));
        $this->assertSame(2, $nombre);
    }

    public function test_desactiver_alerte_stock_active_supprime_le_stock_faible_mais_garde_la_rupture(): void
    {
        $this->produit->update(['alerte_stock_active' => false]);

        $service = app(StockStatutService::class);
        // Stock faible : disparaît si l'utilisateur a choisi de ne pas être alerté.
        $this->assertSame(StockStatut::DISPONIBLE, $service->statutPour(10, 10, false));
        // Rupture : reste vraie quoi qu'il arrive (fait de disponibilité, pas une préférence).
        $this->assertSame(StockStatut::RUPTURE, $service->statutPour(0, 10, false));
    }

    public function test_produit_sans_stock_gere_najoute_jamais_dalerte(): void
    {
        $typeService = ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->firstOrFail();
        $produitService = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Prestation conseil',
            'produit_type_id' => $typeService->id,
            'statut' => 'actif',
            'alerte_stock_active' => true, // même si activé, un type sans stock ne peut jamais alerter
            'seuil_alerte_stock' => 5,
        ]);

        $statut = app(StockStatutService::class)->statutPourVarianteStock(
            $produitService->fresh(['produitType']),
            new VarianteStock(['qte_stock' => 0]),
        );

        $this->assertSame(StockStatut::DISPONIBLE, $statut);
    }

    public function test_variantes_generees_avec_options_nont_plus_de_seuil_propre(): void
    {
        // Aucun seuil par variante n'existe plus (colonne supprimée, cf. migration) : les deux
        // variantes générées via options partagent implicitement le même seuil, celui du produit.
        $this->assertCount(2, $this->produit->variantes);
        $this->assertFalse(Schema::hasColumn('produit_variantes', 'seuil_alerte_stock'));
    }
}
