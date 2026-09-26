<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ScanProduitControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['produits.read']);
    }

    private function typeId(Organization $org): string
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);

        return ProduitType::where('organization_id', $org->id)->where('code', 'achat_vente')->value('id');
    }

    private function makeVariante(Organization $org, string $codeBarres, ?string $sku = null): ProduitVariante
    {
        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Pack Eau 1.5L',
            'produit_type_id' => $this->typeId($org),
            'statut' => 'actif',
        ]);

        return ProduitVariante::create([
            'organization_id' => $org->id,
            'produit_id' => $produit->id,
            'sku' => $sku,
            'code_barres' => $codeBarres,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_produit_trouve_dans_la_meme_organisation_renvoie_sa_fiche(): void
    {
        $variante = $this->makeVariante($this->org, '6120000000015');

        $response = $this->actingAs($this->user)
            ->getJson('/scan/produit/6120000000015');

        $response->assertOk();
        $response->assertJson(['url' => route('produits.show', $variante->produit_id)]);
    }

    public function test_produit_trouve_par_sku_quand_le_code_barres_ne_correspond_pas(): void
    {
        $variante = $this->makeVariante($this->org, '6120000000015', 'REF-0001');

        $response = $this->actingAs($this->user)
            ->getJson('/scan/produit/REF-0001');

        $response->assertOk();
        $response->assertJson(['url' => route('produits.show', $variante->produit_id)]);
    }

    public function test_produit_dune_autre_organisation_est_inaccessible(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->makeVariante($autreOrg, '6120000000015');

        $response = $this->actingAs($this->user)
            ->getJson('/scan/produit/6120000000015');

        $response->assertNotFound();
        $response->assertJson(['url' => null]);
    }

    public function test_utilisateur_sans_permission_produits_read_est_refuse(): void
    {
        $utilisateurSansDroit = $this->makeUserWithPermissions($this->org, []);
        $this->makeVariante($this->org, '6120000000015');

        $response = $this->actingAs($utilisateurSansDroit)
            ->getJson('/scan/produit/6120000000015');

        $response->assertForbidden();
    }

    public function test_code_inconnu_renvoie_404(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/scan/produit/CODE-INEXISTANT');

        $response->assertNotFound();
        $response->assertJson(['url' => null]);
    }
}
