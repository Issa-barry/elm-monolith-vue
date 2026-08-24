<?php

namespace Tests\Feature;

use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\MouvementStockService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Un transfert inter-agences doit décrémenter le site source et incrémenter le
 * site destination — sans jamais toucher une troisième agence. Avant cette
 * refonte, TransfertLogistiqueService::avancerStatut() ne créait qu'une trace
 * MouvementStock (audit) sans jamais mettre à jour VarianteStock : le stock des
 * deux agences ne bougeait pas du tout lors d'un transfert.
 */
class TransfertLogistiqueStockTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $siteSource;

    private Site $siteDestination;

    private Site $siteTiers;

    private User $admin;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->siteSource = $this->makeSite('Site Source');
        $this->siteDestination = $this->makeSite('Site Destination');
        $this->siteTiers = $this->makeSite('Site Tiers');

        $this->admin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->actingAs($this->admin);

        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Eau 19L']);
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

    private function seedStock(Site $site, int $qte): VarianteStock
    {
        return VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $site->id,
            'qte_stock' => $qte,
        ]);
    }

    private function makeTransfertEnChargement(int $qteChargee): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->siteSource->id,
            'site_destination_id' => $this->siteDestination->id,
            'statut' => StatutTransfert::CHARGEMENT,
            'created_by' => $this->admin->id,
        ]);

        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $qteChargee,
            'quantite_chargee' => $qteChargee,
        ]);

        return $transfert;
    }

    public function test_avancer_en_transit_decremente_uniquement_le_site_source(): void
    {
        $this->seedStock($this->siteSource, 100);
        $this->seedStock($this->siteDestination, 20);
        $this->seedStock($this->siteTiers, 40);

        $transfert = $this->makeTransfertEnChargement(30);

        TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertEquals(StatutTransfert::TRANSIT, $transfert->fresh()->statut);
        $variante = $this->produit->variantePrincipale()->first();
        $this->assertEquals(70, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteSource->id)->value('qte_stock'));
        $this->assertEquals(20, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteDestination->id)->value('qte_stock'));
        $this->assertEquals(40, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteTiers->id)->value('qte_stock'));
    }

    public function test_reception_incremente_uniquement_le_site_destination(): void
    {
        $this->seedStock($this->siteSource, 100);
        $this->seedStock($this->siteDestination, 20);
        $this->seedStock($this->siteTiers, 40);

        $transfert = $this->makeTransfertEnChargement(30);
        TransfertLogistiqueService::avancerStatut($transfert); // CHARGEMENT → TRANSIT

        $transfert->fresh()->lignes()->first()->update([
            'quantite_recue' => 30,
            'ecart_type' => TypeEcartLogistique::CONFORME->value,
        ]);

        TransfertLogistiqueService::avancerStatut($transfert->fresh()); // TRANSIT → RECEPTION

        $variante = $this->produit->variantePrincipale()->first();
        $this->assertEquals(70, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteSource->id)->value('qte_stock'));
        $this->assertEquals(50, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteDestination->id)->value('qte_stock'));
        $this->assertEquals(40, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteTiers->id)->value('qte_stock'));

        // Le global reste conservé : 100 + 20 + 40 = 160, inchangé par le transfert.
        $this->assertEquals(160, $this->produit->fresh()->qte_stock);
    }

    public function test_transfert_sur_site_jamais_touche_ne_recupere_pas_le_stock_legacy(): void
    {
        // Legacy : le produit a un total historique de 500, jamais ventilé par site.
        $this->produit->update(['qte_stock' => 500]);

        $transfert = $this->makeTransfertEnChargement(10);

        // Le site source n'a aucune ligne variante_stocks (0 disponible) : le transfert est
        // refusé — jamais un repli implicite sur les 500 du legacy, jamais un clamp silencieux
        // à 0 (cf. correctif du 23/08/2026, remplace l'ancien comportement clampé).
        try {
            TransfertLogistiqueService::avancerStatut($transfert);
            $this->fail('Le transfert aurait dû être refusé pour stock insuffisant.');
        } catch (ValidationException $e) {
            // attendu
        }

        $variante = $this->produit->variantePrincipale()->first();
        $this->assertDatabaseMissing('variante_stocks', [
            'produit_variante_id' => $variante->id,
            'site_id' => $this->siteSource->id,
        ]);
        $this->assertEquals(StatutTransfert::CHARGEMENT, $transfert->fresh()->statut);
    }

    public function test_invalider_une_reception_annule_lentree_stock_destination(): void
    {
        $this->seedStock($this->siteSource, 100);
        $this->seedStock($this->siteDestination, 20);

        $transfert = $this->makeTransfertEnChargement(30);
        TransfertLogistiqueService::avancerStatut($transfert); // → TRANSIT

        $transfert->fresh()->lignes()->first()->update([
            'quantite_recue' => 30,
            'ecart_type' => TypeEcartLogistique::CONFORME->value,
        ]);
        TransfertLogistiqueService::avancerStatut($transfert->fresh()); // → RECEPTION

        $variante = $this->produit->variantePrincipale()->first();
        $this->assertEquals(50, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteDestination->id)->value('qte_stock'));
        $this->assertEquals(1, MouvementStock::where('type', 'entree')->where('site_id', $this->siteDestination->id)->count());

        MouvementStockService::supprimerEntreeDestination($transfert->fresh());

        // L'entrée de 30 est réversée : destination revient à son état d'avant réception.
        $this->assertEquals(20, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteDestination->id)->value('qte_stock'));
        $this->assertEquals(0, MouvementStock::where('type', 'entree')->where('site_id', $this->siteDestination->id)->count());
        // Le site source, lui, n'est jamais touché par une invalidation de réception.
        $this->assertEquals(70, VarianteStock::where('produit_variante_id', $variante->id)->where('site_id', $this->siteSource->id)->value('qte_stock'));
    }
}
