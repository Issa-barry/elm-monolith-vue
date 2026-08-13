<?php

namespace Tests\Unit;

use App\Enums\StockStatut;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Services\StockStatutService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre la règle centrale du chantier "alerte stock" : le seuil vient du PRODUIT, la
 * quantité et l'état vivent au niveau VARIANTE × SITE — un stock confortable ailleurs
 * (autre variante, autre site, total organisation) ne doit jamais masquer une alerte locale.
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

    // ── statutPour() : la règle pure ────────────────────────────────────────────

    public function test_qte_zero_est_toujours_une_rupture(): void
    {
        $this->assertSame(StockStatut::RUPTURE, $this->service->statutPour(0, 10, true));
        $this->assertSame(StockStatut::RUPTURE, $this->service->statutPour(0, 10, false));
    }

    public function test_rupture_est_independante_du_choix_alerte(): void
    {
        // La rupture est un fait de disponibilité, pas une préférence de notification —
        // elle reste vraie même si l'utilisateur a choisi de ne pas être alerté.
        $this->assertSame(StockStatut::RUPTURE, $this->service->statutPour(0, 10, false));
    }

    public function test_stock_faible_seulement_si_alerte_active(): void
    {
        $this->assertSame(StockStatut::STOCK_FAIBLE, $this->service->statutPour(5, 10, true));
        $this->assertSame(StockStatut::DISPONIBLE, $this->service->statutPour(5, 10, false));
    }

    public function test_qte_egale_au_seuil_est_stock_faible(): void
    {
        $this->assertSame(StockStatut::STOCK_FAIBLE, $this->service->statutPour(10, 10, true));
    }

    public function test_qte_strictement_superieure_au_seuil_est_disponible(): void
    {
        $this->assertSame(StockStatut::DISPONIBLE, $this->service->statutPour(11, 10, true));
    }

    public function test_seuil_zero_desactive_le_stock_faible(): void
    {
        $this->assertSame(StockStatut::DISPONIBLE, $this->service->statutPour(5, 0, true));
    }

    // ── seuilEffectif() : repli sur le seuil global de l'organisation ──────────

    public function test_seuil_effectif_utilise_le_seuil_produit_si_defini(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit test',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'seuil_alerte_stock' => 5,
        ]);

        $this->assertSame(5, $this->service->seuilEffectif($produit));
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

    public function test_seuil_effectif_replie_sur_le_seuil_global_si_absent(): void
    {
        $org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
        $type = ProduitType::where('organization_id', $org->id)->where('code', 'materiel')->first();
        $this->setSeuilGlobal($org->id, 20);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit sans seuil',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'seuil_alerte_stock' => null,
        ]);

        $this->assertSame(20, $this->service->seuilEffectif($produit));
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

        $produitA = Produit::create(['organization_id' => $orgA->id, 'nom' => 'A', 'produit_type_id' => $typeA->id, 'statut' => 'actif']);
        $produitB = Produit::create(['organization_id' => $orgB->id, 'nom' => 'B', 'produit_type_id' => $typeB->id, 'statut' => 'actif']);

        $this->assertSame(5, $this->service->seuilEffectif($produitA));
        $this->assertSame(50, $this->service->seuilEffectif($produitB));
    }
}
