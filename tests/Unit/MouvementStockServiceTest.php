<?php

namespace Tests\Unit;

use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\MouvementStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Couvre le point d'entrée unique de mutation du stock (correctif du 23/08/2026 : suppression
 * du clamp silencieux `max(0, $stockAvant + $delta)`). Règle invariante testée ici :
 * stock_apres = stock_avant + delta, TOUJOURS — une sortie qui dépasse le disponible est soit
 * refusée en entier (aucun mouvement créé, stock inchangé), soit appliquée en entier (stock
 * autorisé à devenir négatif) — jamais un clamp partiel qui rendrait le mouvement enregistré
 * incohérent avec le solde réel.
 */
class MouvementStockServiceTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $user;

    private string $varianteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit Test']);
        $this->varianteId = $produit->variantePrincipale()->first()->id;
    }

    private function seedStock(int $qte): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->varianteId, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    public function test_sortie_dans_la_limite_du_stock_est_appliquee_entierement(): void
    {
        $this->seedStock(100);

        $mouvement = MouvementStockService::appliquer(
            varianteId: $this->varianteId,
            siteId: $this->site->id,
            orgId: $this->org->id,
            type: 'sortie',
            quantite: 100,
            userId: $this->user->id,
        );

        $this->assertSame(100, $mouvement->stock_avant);
        $this->assertSame(0, $mouvement->stock_apres);
        $this->assertSame(0, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_stock'));
    }

    public function test_sortie_superieure_au_stock_est_refusee_par_defaut(): void
    {
        $this->seedStock(3);

        try {
            MouvementStockService::appliquer(
                varianteId: $this->varianteId,
                siteId: $this->site->id,
                orgId: $this->org->id,
                type: 'sortie',
                quantite: 5,
                userId: $this->user->id,
            );
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu
        }

        // Refusée EN ENTIER : aucun mouvement créé, stock strictement inchangé — jamais un
        // clamp qui appliquerait une partie du delta (cf. bug audit du 23/08/2026).
        $this->assertSame(3, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_stock'));
        $this->assertSame(0, MouvementStock::where('produit_variante_id', $this->varianteId)->count());
    }

    public function test_sortie_superieure_au_stock_est_appliquee_entierement_si_allow_negative(): void
    {
        $this->seedStock(3);

        $mouvement = MouvementStockService::appliquer(
            varianteId: $this->varianteId,
            siteId: $this->site->id,
            orgId: $this->org->id,
            type: 'sortie',
            quantite: 5,
            userId: $this->user->id,
            allowNegative: true,
        );

        // La quantité DEMANDÉE est appliquée en entier, jamais réduite silencieusement.
        $this->assertSame(5, $mouvement->quantite);
        $this->assertSame(3, $mouvement->stock_avant);
        $this->assertSame(-2, $mouvement->stock_apres);
        $this->assertSame(-2, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_stock'));
    }

    public function test_entree_sur_stock_negatif_reste_mathematiquement_correcte(): void
    {
        $this->seedStock(-250);

        // Un réapprovisionnement (réception achat/production/transfert) ne doit jamais
        // artificiellement remettre le stock à 0 avant d'appliquer l'entrée.
        $mouvement = MouvementStockService::appliquer(
            varianteId: $this->varianteId,
            siteId: $this->site->id,
            orgId: $this->org->id,
            type: 'entree',
            quantite: 1000,
            userId: $this->user->id,
        );

        $this->assertSame(-250, $mouvement->stock_avant);
        $this->assertSame(750, $mouvement->stock_apres);
        $this->assertSame(750, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_stock'));
    }

    /**
     * Invariant central du correctif : stock_apres = stock_avant + delta, quel que soit le
     * signe du résultat — jamais borné à 0. Garde-fou explicite contre le retour du clamp.
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function stockAvantDeltaProvider(): array
    {
        return [
            'positif vers positif' => [100, -30, 70],
            'positif vers zero exact' => [50, -50, 0],
            'positif vers negatif' => [3, -5, -2],
            'zero vers negatif' => [0, -20, -20],
            'negatif vers plus negatif' => [-10, -15, -25],
            'negatif vers positif (reapprovisionnement)' => [-250, 1000, 750],
        ];
    }

    #[DataProvider('stockAvantDeltaProvider')]
    public function test_stock_apres_est_toujours_stock_avant_plus_delta(int $stockAvant, int $delta, int $stockApresAttendu): void
    {
        $this->seedStock($stockAvant);

        $mouvement = MouvementStockService::appliquer(
            varianteId: $this->varianteId,
            siteId: $this->site->id,
            orgId: $this->org->id,
            type: $delta >= 0 ? 'entree' : 'sortie',
            quantite: abs($delta),
            userId: $this->user->id,
            allowNegative: true,
        );

        $this->assertSame($stockAvant, $mouvement->stock_avant);
        $this->assertSame($stockApresAttendu, $mouvement->stock_apres);
        $this->assertSame($stockAvant + $delta, $mouvement->stock_apres);
    }
}
