<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Couvre l'adaptation de ProduitService::creer() pour l'import produits (cf. plan
 * squishy-launching-mochi) : un SKU explicite fourni à la création doit être respecté tel
 * quel, jamais réécrit par mettreAJourSimple(), et jamais autorisé en combinaison avec des
 * déclinaisons (options).
 */
class ProduitServiceCreationAvecSkuTest extends TestCase
{
    use RefreshDatabase;

    private ProduitService $service;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProduitService::class);
        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
    }

    private function typeId(string $code): string
    {
        return ProduitType::where('organization_id', $this->org->id)->where('code', $code)->value('id');
    }

    private function creer(array $overrides): Produit
    {
        return $this->service->creer(array_merge([
            'organization_id' => $this->org->id,
            'nom' => 'Produit test',
            'produit_type_id' => $this->typeId('achat_vente'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ], $overrides));
    }

    public function test_creer_sans_sku_genere_reste_inchange(): void
    {
        $produit = $this->creer([]);

        $this->assertNotEmpty($produit->variantes->first()->sku);
        // Le SKU auto-généré est séquentiel par organisation (Organization::prochaineReferenceProduit),
        // jamais dérivé du nom/prix.
        $this->assertIsNumeric($produit->variantes->first()->sku);
    }

    public function test_creer_avec_sku_explicite_le_respecte_au_lieu_de_generer(): void
    {
        $produit = $this->creer(['sku' => 'IMPORT-0001']);

        $this->assertSame('IMPORT-0001', $produit->variantes->first()->sku);
    }

    public function test_creer_avec_sku_explicite_et_options_est_refuse(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'sku' => 'IMPORT-0002',
            'options' => [
                ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
            ],
        ]);
    }

    public function test_creer_avec_sku_deja_utilise_dans_lorganisation_echoue(): void
    {
        $this->creer(['sku' => 'IMPORT-0003']);

        $this->expectException(QueryException::class);

        $this->creer(['sku' => 'IMPORT-0003']);
    }

    public function test_mettre_a_jour_simple_naccepte_jamais_le_sku(): void
    {
        $produit = $this->creer(['sku' => 'IMPORT-0004']);

        $produit = $this->service->mettreAJourSimple($produit, [
            'nom' => 'Produit renommé',
            'sku' => 'TENTATIVE-DE-MODIF',
        ]);

        $this->assertSame('IMPORT-0004', $produit->variantes->first()->sku);
    }
}
