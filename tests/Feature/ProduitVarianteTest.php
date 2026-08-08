<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Services\ProduitService;
use App\Services\VarianteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ProduitVarianteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['produits.read', 'produits.create']);
    }

    private function makeProduitSansVariante(): Produit
    {
        return Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'T-shirt',
            'type' => 'achat_vente',
            'statut' => 'actif',
        ]);
    }

    // ── Produit simple → variante par défaut ────────────────────────────────────

    public function test_creer_produit_simple_cree_une_variante_par_defaut(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Eau 500ml',
            'type' => 'fabricable',
            'statut' => 'actif',
            'prix_usine' => 4000,
            'prix_vente' => 5000,
        ]);

        $this->assertCount(1, $produit->variantes);
        $variante = $produit->variantes->first();
        $this->assertTrue($variante->is_default);
        $this->assertSame('default', $variante->combo_hash);
        $this->assertNotEmpty($variante->code_interne);
        $this->assertSame('', $variante->libelle);
    }

    // ── Génération cartésienne ───────────────────────────────────────────────────

    public function test_genere_une_variante_par_valeur_pour_une_seule_option(): void
    {
        $produit = $this->makeProduitSansVariante();

        $variantes = app(VarianteService::class)->genererVariantes($produit, [
            ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc', 'Rouge']],
        ]);

        $this->assertCount(3, $variantes);
        $this->assertSame(['Noir', 'Blanc', 'Rouge'], $variantes->pluck('libelle')->all());
        $this->assertTrue($variantes->every(fn ($v) => ! $v->is_default));
    }

    public function test_genere_le_produit_cartesien_pour_plusieurs_options(): void
    {
        $produit = $this->makeProduitSansVariante();

        $variantes = app(VarianteService::class)->genererVariantes($produit, [
            ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
            ['nom' => 'Taille', 'valeurs' => ['S', 'M', 'L']],
        ]);

        $this->assertCount(6, $variantes); // 2 × 3
        $libelles = $variantes->pluck('libelle')->sort()->values()->all();
        $attendus = collect([
            'Noir / S', 'Noir / M', 'Noir / L',
            'Blanc / S', 'Blanc / M', 'Blanc / L',
        ])->sort()->values()->all();
        $this->assertSame($attendus, $libelles);
    }

    public function test_chaque_variante_a_une_combinaison_unique(): void
    {
        $produit = $this->makeProduitSansVariante();

        $variantes = app(VarianteService::class)->genererVariantes($produit, [
            ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
            ['nom' => 'Taille', 'valeurs' => ['S', 'M']],
        ]);

        $hashes = $variantes->pluck('combo_hash');
        $this->assertSame($hashes->count(), $hashes->unique()->count());
    }

    public function test_chaque_variante_a_un_sku_distinct(): void
    {
        $produit = $this->makeProduitSansVariante();

        $variantes = app(VarianteService::class)->genererVariantes($produit, [
            ['nom' => 'Taille', 'valeurs' => ['S', 'M', 'L']],
        ]);

        $skus = $variantes->pluck('code_interne');
        $this->assertSame($skus->count(), $skus->unique()->count());
    }

    // ── Limites catalogue (par organisation) ─────────────────────────────────────

    public function test_refuse_plus_doptions_que_la_limite_organisation(): void
    {
        Parametre::setLimitesCatalogue($this->org->id, 6, 2, 20, 100);
        $produit = $this->makeProduitSansVariante();

        $this->expectException(ValidationException::class);

        app(VarianteService::class)->genererVariantes($produit, [
            ['nom' => 'Couleur', 'valeurs' => ['Noir']],
            ['nom' => 'Taille', 'valeurs' => ['S']],
            ['nom' => 'Matière', 'valeurs' => ['Coton']],
        ]);
    }

    public function test_refuse_plus_de_valeurs_que_la_limite_organisation(): void
    {
        Parametre::setLimitesCatalogue($this->org->id, 6, 3, 2, 100);
        $produit = $this->makeProduitSansVariante();

        $this->expectException(ValidationException::class);

        app(VarianteService::class)->genererVariantes($produit, [
            ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc', 'Rouge']],
        ]);
    }

    public function test_refuse_si_le_total_de_variantes_depasse_la_limite_organisation(): void
    {
        Parametre::setLimitesCatalogue($this->org->id, 6, 3, 20, 5);
        $produit = $this->makeProduitSansVariante();

        try {
            app(VarianteService::class)->genererVariantes($produit, [
                ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
                ['nom' => 'Taille', 'valeurs' => ['S', 'M', 'L']],
            ]);
            $this->fail('Une ValidationException était attendue (2×3=6 > limite 5).');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('6 variantes', $e->validator->errors()->first('options'));
            $this->assertStringContainsString('limite configurée', $e->validator->errors()->first('options'));
        }
    }

    public function test_les_limites_sont_scopees_par_organisation(): void
    {
        Parametre::setLimitesCatalogue($this->org->id, 6, 1, 20, 100);

        $autreOrg = Organization::factory()->create();
        Parametre::setLimitesCatalogue($autreOrg->id, 6, 5, 20, 100);

        // 2 options passent pour l'autre organisation (limite 5) mais pas pour la nôtre (limite 1)
        $this->assertSame(1, Parametre::getMaxOptionsProduit($this->org->id));
        $this->assertSame(5, Parametre::getMaxOptionsProduit($autreOrg->id));
    }

    // ── Valeurs par défaut ────────────────────────────────────────────────────────

    public function test_valeurs_par_defaut_des_limites_catalogue(): void
    {
        $orgSansParametre = Organization::factory()->create();

        $this->assertSame(6, Parametre::getMaxPhotosProduit($orgSansParametre->id));
        $this->assertSame(3, Parametre::getMaxOptionsProduit($orgSansParametre->id));
        $this->assertSame(20, Parametre::getMaxValeursOption($orgSansParametre->id));
        $this->assertSame(100, Parametre::getMaxVariantesProduit($orgSansParametre->id));
    }
}
