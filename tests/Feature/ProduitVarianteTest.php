<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Services\ProduitService;
use App\Services\VarianteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->assertNotEmpty($variante->sku);
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

        $skus = $variantes->pluck('sku');
        $this->assertSame($skus->count(), $skus->unique()->count());
    }

    // ── Référence produit (sku) ──────────────────────────────────────────────────
    // Génération style numéro d'article IKEA : entier séquentiel par organisation,
    // stable, n'encode ni le nom, ni la catégorie, ni le prix (cf. Organization::
    // prochaineReferenceProduit() et ProduitVariante::booted()).

    public function test_reference_generee_automatiquement_est_purement_numerique(): void
    {
        $produit = $this->makeProduitSansVariante();
        $variante = app(VarianteService::class)->creerVarianteParDefaut($produit, []);

        $this->assertMatchesRegularExpression('/^\d+$/', $variante->sku);
    }

    public function test_references_sont_sequentielles_pour_une_meme_organisation(): void
    {
        $premier = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit A',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);
        $second = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit B',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);

        $refA = (int) $premier->variantes->first()->sku;
        $refB = (int) $second->variantes->first()->sku;
        $this->assertSame($refA + 1, $refB);
    }

    public function test_reference_reste_stable_apres_modification_du_produit(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Nom initial',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);
        $referenceInitiale = $produit->variantes->first()->sku;

        $autreCategorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre catégorie',
            'statut' => 'actif',
        ]);

        app(ProduitService::class)->mettreAJourSimple($produit, [
            'nom' => 'Nom modifié',
            'type' => 'materiel',
            'statut' => 'actif',
            'categorie_id' => $autreCategorie->id,
            'prix_achat' => 999,
            'prix_vente' => 1999,
        ]);

        $this->assertSame($referenceInitiale, $produit->fresh()->variantes->first()->sku);
    }

    public function test_deux_organisations_peuvent_partager_la_meme_reference(): void
    {
        $autreOrg = Organization::factory()->create();

        $produitOrgA = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit org A',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);
        $produitOrgB = app(ProduitService::class)->creer([
            'organization_id' => $autreOrg->id,
            'nom' => 'Produit org B',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);

        // Chaque organisation a son propre compteur : le premier produit de chacune
        // reçoit la même référence, sans collision (unicité scopée par organisation).
        $this->assertSame(
            $produitOrgA->variantes->first()->sku,
            $produitOrgB->variantes->first()->sku,
        );
    }

    public function test_produit_peut_etre_cree_sans_categorie(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit sans catégorie',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);

        $this->assertNull($produit->categorie_id);
        $this->assertNotEmpty($produit->variantes->first()->sku);
    }

    // ── Code-barres (distinct de la référence) ───────────────────────────────────

    public function test_creation_avec_reference_et_code_barres(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit avec code-barres',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
            'code_barres' => '3274080005003',
        ]);

        $variante = $produit->variantes->first();
        $this->assertNotEmpty($variante->sku);
        $this->assertSame('3274080005003', $variante->code_barres);
        $this->assertNotSame($variante->sku, $variante->code_barres);
    }

    public function test_creation_avec_reference_sans_code_barres(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit sans code-barres',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);

        $variante = $produit->variantes->first();
        $this->assertNotEmpty($variante->sku);
        $this->assertNull($variante->code_barres);
    }

    public function test_sku_est_obligatoire_au_niveau_base_de_donnees(): void
    {
        $this->expectException(QueryException::class);

        // Contournement volontaire du modèle (Eloquent l'aurait auto-généré) pour vérifier
        // que la colonne elle-même impose la référence — dernier filet de sécurité si un
        // jour un code y échappe.
        DB::table('produit_variantes')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $this->org->id,
            'produit_id' => $this->makeProduitSansVariante()->id,
            'combo_hash' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unicite_de_la_reference_par_organisation(): void
    {
        $produit = $this->makeProduitSansVariante();
        $variante = app(VarianteService::class)->creerVarianteParDefaut($produit, []);

        $this->expectException(QueryException::class);

        ProduitVariante::create([
            'organization_id' => $this->org->id,
            'produit_id' => $produit->id,
            'sku' => $variante->sku,
            'combo_hash' => 'autre',
        ]);
    }

    public function test_unicite_du_code_barres_par_organisation(): void
    {
        app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Premier produit',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
            'code_barres' => '3274080005003',
        ]);

        $this->expectException(QueryException::class);

        // Le doublon n'est pas intercepté par une règle de validation métier ici : c'est la
        // contrainte unique(['organization_id', 'code_barres']) en base qui tranche, même
        // appel direct au service (hors couche HTTP/FormRequest).
        app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Deuxième produit',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
            'code_barres' => '3274080005003',
        ]);
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
