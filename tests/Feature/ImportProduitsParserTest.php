<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\ImportProduits;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use App\Models\User;
use App\Services\ImportProduits\ImportProduitsParser;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ImportProduitsParserTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ImportProduitsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $this->parser = new ImportProduitsParser(app(ProduitService::class));
    }

    private function typeId(string $code): string
    {
        return ProduitType::where('organization_id', $this->org->id)->where('code', $code)->value('id');
    }

    private function ligne(array $overrides = []): Collection
    {
        return collect(array_replace([
            'sku' => '',
            'nom' => 'Produit test',
            'type_code' => 'achat_vente',
            'categorie_reference' => '',
            'fournisseur_reference' => '',
            'statut' => 'actif',
            'code_barres' => '',
            'prix_achat' => '1000',
            'prix_usine' => '',
            'prix_usine_tricycle' => '',
            'prix_vente' => '1500',
            'cout' => '',
            'alerte_stock_active' => 'non',
            'seuil_alerte_stock' => '',
            'description' => '',
        ], $overrides));
    }

    private function creerProduitSimple(array $overrides = []): Produit
    {
        return app(ProduitService::class)->creer(array_merge([
            'organization_id' => $this->org->id,
            'nom' => 'Produit existant',
            'produit_type_id' => $this->typeId('achat_vente'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
            'alerte_stock_active' => false,
        ], $overrides));
    }

    // ── Classification par SKU ───────────────────────────────────────────────

    public function test_sku_vide_est_toujours_une_creation(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne()]), $this->org->id);

        $this->assertSame('creation', $resultat['lignes'][0]['statut']);
        $this->assertEmpty($resultat['lignes'][0]['erreurs']);
    }

    public function test_sku_renseigne_mais_introuvable_est_une_creation_avec_avertissement(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => 'NOUVEAU-001'])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('creation', $ligne['statut']);
        $this->assertSame('NOUVEAU-001', $ligne['data']['sku']);
        $this->assertNotEmpty($ligne['avertissements']);
    }

    public function test_sku_dune_variante_par_defaut_existante_est_une_mise_a_jour(): void
    {
        $produit = $this->creerProduitSimple(['prix_vente' => 1500]);
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku, 'nom' => '', 'prix_vente' => '2000'])]), $this->org->id);

        $this->assertSame('mise_a_jour', $resultat['lignes'][0]['statut']);
    }

    public function test_lignes_identiques_au_produit_existant_sont_classees_inchange(): void
    {
        $produit = $this->creerProduitSimple(['nom' => 'Sel Alpha', 'prix_achat' => 1000, 'prix_vente' => 1500]);
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne([
            'sku' => $sku, 'nom' => 'Sel Alpha', 'prix_achat' => '1000', 'prix_vente' => '1500',
        ])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('inchange', $ligne['statut']);
        $this->assertEmpty($ligne['changements']);
    }

    public function test_prix_modifie_est_classe_mise_a_jour_avec_le_diff(): void
    {
        $produit = $this->creerProduitSimple(['prix_achat' => 1000, 'prix_vente' => 1500]);
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne([
            'sku' => $sku, 'nom' => '', 'prix_achat' => '1000', 'prix_vente' => '1600',
        ])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('mise_a_jour', $ligne['statut']);
        $this->assertSame(['avant' => 1500, 'apres' => 1600], $ligne['changements']['prix_vente']);
        $this->assertSame(['prix_vente' => 1600], $ligne['data']);
    }

    public function test_nouvelle_colonne_prix_usine_autres_vehicules_alimente_le_champ_metier_prix_usine(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne([
            'prix_achat' => '',
            'prix_usine' => '',
            'prix_usine_autres_vehicules' => '18000',
            'prix_usine_tricycle' => '17500',
            'prix_vente' => '20000',
            'prix_externe' => '1',
            'prix_revendeur' => '1',
            'prix_distributeur' => '1',
            'type_code' => 'fabricable',
        ])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('creation', $ligne['statut']);
        $this->assertSame(18000, $ligne['data']['prix_usine']);
        $this->assertSame(17500, $ligne['data']['prix_usine_tricycle']);
    }

    public function test_ancienne_colonne_prix_usine_reste_acceptee(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne([
            'prix_achat' => '',
            'prix_usine' => '18000',
            'prix_usine_tricycle' => '17500',
            'prix_vente' => '20000',
            'prix_externe' => '1',
            'prix_revendeur' => '1',
            'prix_distributeur' => '1',
            'type_code' => 'fabricable',
        ])]), $this->org->id);

        $this->assertSame('creation', $resultat['lignes'][0]['statut']);
        $this->assertSame(18000, $resultat['lignes'][0]['data']['prix_usine']);
    }

    public function test_deux_colonnes_prix_usine_differentes_sont_rejetees_comme_ambigues(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne([
            'prix_achat' => '',
            'prix_usine' => '18000',
            'prix_usine_autres_vehicules' => '19000',
            'prix_usine_tricycle' => '17500',
            'prix_vente' => '21000',
            'prix_externe' => '1',
            'prix_revendeur' => '1',
            'prix_distributeur' => '1',
            'type_code' => 'fabricable',
        ])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('erreur', $ligne['statut']);
        $this->assertStringContainsString('valeur différente', implode(' ', $ligne['erreurs']));
    }

    /**
     * Les trois tarifs par nature de client sont obligatoires pour un produit fabricable —
     * l'absence d'un seul (ici prix_distributeur) doit être détectée par l'import, exactement
     * comme le fait ProduitService::validerPrixSelonType() côté formulaire web/API.
     */
    public function test_fabricable_avec_un_seul_prix_par_nature_manquant_est_une_erreur_claire(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne([
            'prix_achat' => '',
            'prix_usine' => '18000',
            'prix_usine_tricycle' => '17500',
            'prix_vente' => '20000',
            'prix_externe' => '18250',
            'prix_revendeur' => '19000',
            'type_code' => 'fabricable',
        ])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('erreur', $ligne['statut']);
        $this->assertStringContainsString('obligatoires', implode(' ', $ligne['erreurs']));
    }

    public function test_sku_dune_variante_non_default_est_rejete(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit variantes',
            'produit_type_id' => $this->typeId('achat_vente'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
            'options' => [['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']]],
        ])->fresh(['variantes']);

        $skuNonDefault = $produit->variantes->firstWhere('is_default', false)->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $skuNonDefault])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('erreur', $ligne['statut']);
        $this->assertStringContainsString('hors périmètre', $ligne['erreurs'][0]);
    }

    public function test_sku_dun_produit_a_variantes_multiples_via_sa_variante_par_defaut_est_rejete(): void
    {
        // La variante PAR DÉFAUT elle-même d'un produit à options existe aussi (is_default=true,
        // combo_hash='default') mais le produit possède >1 variante au total : hors périmètre.
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit variantes',
            'produit_type_id' => $this->typeId('achat_vente'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
            'options' => [['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']]],
        ])->fresh(['variantes']);

        // creerVarianteParDefaut n'est jamais appelé quand des options sont fournies (cf.
        // ProduitService::creer()) : il n'y a donc pas de variante is_default=true ici — ce test
        // documente que le cas ne se produit pas en pratique tel quel. On le remplace par la
        // vérification directe du garde-fou sur le nombre de variantes via une variante
        // explicitement forcée en défaut (is_default + combo_hash='default') pour prouver que
        // le comptage du nombre de variantes prime sur ces deux seuls indicateurs.
        $variante = $produit->variantes->first();
        $variante->update(['is_default' => true, 'combo_hash' => ProduitVariante::COMBO_HASH_DEFAUT]);

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $variante->sku])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('erreur', $ligne['statut']);
        $this->assertStringContainsString('plusieurs variantes', $ligne['erreurs'][0]);
    }

    public function test_sku_archive_est_rejete(): void
    {
        $produit = $this->creerProduitSimple();
        $variante = $produit->variantes->first();
        $sku = $variante->sku;
        $variante->delete();

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('erreur', $ligne['statut']);
        $this->assertStringContainsString('archivée', $ligne['erreurs'][0]);
    }

    public function test_sku_duplique_dans_le_fichier_est_rejete(): void
    {
        $resultat = $this->parser->analyser(collect([
            $this->ligne(['sku' => 'DUP-001']),
            $this->ligne(['sku' => 'DUP-001', 'nom' => 'Autre nom']),
        ]), $this->org->id);

        $this->assertSame('creation', $resultat['lignes'][0]['statut']);
        $this->assertSame('erreur', $resultat['lignes'][1]['statut']);
        $this->assertStringContainsString('apparaît déjà ligne 2', $resultat['lignes'][1]['erreurs'][0]);
    }

    /**
     * La comparaison de SKU reproduit EXACTEMENT ProduitVariante::setSkuAttribute() (espaces
     * retirés + majuscules), jamais la tolérance zéros initiaux/tirets de
     * ReferenceValueResolver::normalizeCodeKey() (conçue pour des codes de site, pas pour un
     * identifiant qui déclenche une écriture ciblée) — "007" et "7" sont deux SKU distincts en
     * base et doivent le rester à l'import, sous peine de mettre à jour le mauvais produit.
     */
    public function test_sku_001_et_1_restent_distincts(): void
    {
        $this->creerProduitSimple(['sku' => '007', 'prix_vente' => 1500]);

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => '7'])]), $this->org->id);

        // "7" ne correspond à aucune variante existante ("007" est un SKU distinct) : création
        // avec ce SKU explicite, jamais un rapprochement vers le produit portant "007".
        $ligne = $resultat['lignes'][0];
        $this->assertSame('creation', $ligne['statut']);
        $this->assertSame('7', $ligne['data']['sku']);
    }

    public function test_sku_avec_tiret_et_sans_tiret_restent_distincts(): void
    {
        $this->creerProduitSimple(['sku' => 'ABC-1', 'prix_vente' => 1500]);

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => 'ABC1'])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('creation', $ligne['statut']);
        $this->assertSame('ABC1', $ligne['data']['sku']);
    }

    public function test_sku_identique_apres_normalisation_du_mutateur_est_bien_reconnu(): void
    {
        // Le mutateur uppercase + retire les espaces : "abc-1" saisi dans le fichier doit
        // toujours retrouver "ABC-1" stocké (seule tolérance légitime, car c'est aussi la seule
        // que le modèle applique lui-même à l'écriture).
        $produit = $this->creerProduitSimple(['sku' => 'ABC-1', 'prix_vente' => 1500]);

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => ' abc-1 ', 'prix_vente' => '2000'])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('mise_a_jour', $ligne['statut']);
        $this->assertSame($produit->id, $ligne['produit_id']);
    }

    // ── #VIDER# et cellule vide ──────────────────────────────────────────────

    public function test_cellule_vide_conserve_la_valeur_existante(): void
    {
        $produit = $this->creerProduitSimple(['description' => 'Description initiale']);
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku, 'nom' => '', 'description' => ''])]), $this->org->id);

        $this->assertSame('inchange', $resultat['lignes'][0]['statut']);
    }

    public function test_videur_efface_un_champ_nullable_en_mise_a_jour(): void
    {
        $produit = $this->creerProduitSimple(['description' => 'À supprimer']);
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku, 'nom' => '', 'description' => '#VIDER#'])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('mise_a_jour', $ligne['statut']);
        $this->assertNull($ligne['changements']['description']['apres']);
        $this->assertNull($ligne['data']['description']);
    }

    public function test_videur_sur_nom_est_refuse_en_creation(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne(['nom' => '#VIDER#'])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    public function test_videur_sur_nom_est_refuse_en_mise_a_jour(): void
    {
        $produit = $this->creerProduitSimple();
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku, 'nom' => '#VIDER#'])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    public function test_videur_sur_statut_est_refuse(): void
    {
        $produit = $this->creerProduitSimple();
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku, 'nom' => '', 'statut' => '#VIDER#'])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    public function test_videur_sur_champ_facultatif_est_refuse_en_creation(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne(['code_barres' => '#VIDER#'])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    // ── SKU jamais modifiable ────────────────────────────────────────────────

    public function test_le_sku_ne_fait_jamais_partie_du_payload_de_mise_a_jour(): void
    {
        $produit = $this->creerProduitSimple();
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku, 'nom' => 'Nouveau nom'])]), $this->org->id);

        $this->assertArrayNotHasKey('sku', $resultat['lignes'][0]['data']);
    }

    // ── type_code jamais modifiable en mise à jour ───────────────────────────

    /**
     * Le changement de type est autorisé en mise à jour (contrairement au SKU, jamais listé
     * comme "non modifiable" par le brief) — les prix effectifs sont revalidés contre les
     * règles du NOUVEAU type, via ProduitService::validerPrixSelonType(), jamais dupliquées.
     * "materiel" n'impose que prix_achat (déjà présent sur le produit achat_vente créé ici) et
     * n'a pas de champ_prix_reference : la transition est donc valide sans donnée
     * supplémentaire.
     */
    public function test_type_code_different_en_mise_a_jour_change_reellement_le_type(): void
    {
        $produit = $this->creerProduitSimple();
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne([
            'sku' => $sku, 'nom' => '', 'type_code' => 'materiel',
        ])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('mise_a_jour', $ligne['statut']);
        $this->assertSame($this->typeId('materiel'), $ligne['data']['produit_type_id']);
        $this->assertSame(['avant' => 'achat_vente', 'apres' => 'materiel'], $ligne['changements']['type']);
    }

    public function test_type_code_introuvable_en_mise_a_jour_est_une_erreur(): void
    {
        $produit = $this->creerProduitSimple();
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne([
            'sku' => $sku, 'nom' => '', 'type_code' => 'code-inexistant',
        ])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    /**
     * Un changement de type qui rendrait les prix effectifs incohérents (ici : passage vers
     * "fabricable", qui exige prix_usine/prix_usine_tricycle, absents sur ce produit
     * achat_vente) doit être rejeté — jamais appliqué avec des prix manquants.
     */
    public function test_changement_de_type_refuse_si_prix_effectifs_incoherents(): void
    {
        $produit = $this->creerProduitSimple();
        $sku = $produit->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne([
            'sku' => $sku, 'nom' => '', 'type_code' => 'fabricable',
        ])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    // ── Code-barres ───────────────────────────────────────────────────────────

    public function test_code_barres_deja_utilise_est_rejete(): void
    {
        $this->creerProduitSimple(['code_barres' => 'EAN-123']);

        $resultat = $this->parser->analyser(collect([$this->ligne(['code_barres' => 'EAN-123'])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    // ── Prix incohérents avec le type ────────────────────────────────────────

    public function test_prix_vente_inferieur_au_prix_reference_est_rejete(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne([
            'prix_achat' => '1000', 'prix_vente' => '900',
        ])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    // ── Résolution de références (categorie/fournisseur) ─────────────────────

    public function test_categorie_reference_inconnue_est_rejetee(): void
    {
        $resultat = $this->parser->analyser(collect([$this->ligne(['categorie_reference' => 'INCONNUE'])]), $this->org->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    public function test_categorie_reference_valide_resout_lid(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Eau en bouteille']);

        $resultat = $this->parser->analyser(collect([$this->ligne(['categorie_reference' => $categorie->reference])]), $this->org->id);

        $ligne = $resultat['lignes'][0];
        $this->assertSame('creation', $ligne['statut']);
        $this->assertSame($categorie->id, $ligne['data']['categorie_id']);
    }

    // ── Isolation multi-organisation ─────────────────────────────────────────

    public function test_sku_dune_autre_organisation_nest_jamais_rapproche(): void
    {
        $autreOrg = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($autreOrg->id);
        $produitAutreOrg = app(ProduitService::class)->creer([
            'organization_id' => $autreOrg->id,
            'nom' => 'Produit autre org',
            'produit_type_id' => ProduitType::where('organization_id', $autreOrg->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $skuAutreOrg = $produitAutreOrg->variantes->first()->sku;

        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $skuAutreOrg])]), $this->org->id);

        // Le SKU (séquentiel par organisation) peut coïncider avec un SKU de l'autre org sans
        // jamais s'y rapprocher : traité comme une création dans CETTE organisation.
        $this->assertSame('creation', $resultat['lignes'][0]['statut']);
    }

    public function test_type_code_dune_autre_organisation_est_rejete(): void
    {
        $autreOrg = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($autreOrg->id);

        // Les codes sont partagés textuellement entre organisations ('achat_vente' existe
        // partout) : on vérifie plutôt qu'un code qui n'existe QUE dans l'organisation courante
        // n'est jamais résolu pour une autre organisation.
        $typeSpecifique = ProduitType::create([
            'organization_id' => $this->org->id,
            'nom' => 'Type Spécifique Org A',
            'gere_stock' => true,
            'vendable' => true,
            'achetable' => false,
            'statut' => 'actif',
        ]);

        $resultat = $this->parser->analyser(collect([$this->ligne(['type_code' => $typeSpecifique->code])]), $autreOrg->id);

        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
    }

    // ── Limite de lignes ──────────────────────────────────────────────────────

    public function test_plus_de_500_lignes_est_rejete_globalement(): void
    {
        $lignes = collect(range(1, 501))->map(fn () => $this->ligne());

        $resultat = $this->parser->analyser($lignes, $this->org->id);

        $this->assertCount(1, $resultat['lignes']);
        $this->assertSame('erreur', $resultat['lignes'][0]['statut']);
        $this->assertStringContainsString('trop de lignes', $resultat['lignes'][0]['erreurs'][0]);
    }

    // ── Fichier déjà importé (détection par hash) ────────────────────────────

    public function test_fichier_deja_importe_avec_creations_sans_sku_est_signale(): void
    {
        $import = ImportProduits::create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'fichier_original' => 'test.xlsx',
            'fichier_path' => 'imports-produits/test.xlsx',
            'fichier_hash' => 'abc123',
            'statut' => 'termine',
            'termine_le' => now(),
        ]);

        $resultat = $this->parser->analyser(collect([$this->ligne()]), $this->org->id, 'abc123');

        $this->assertNotNull($resultat['fichier_deja_importe']);
        $this->assertSame($import->id, $resultat['fichier_deja_importe']['import_id']);
    }

    public function test_fichier_modifie_hash_different_nest_jamais_signale(): void
    {
        ImportProduits::create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'fichier_original' => 'test.xlsx',
            'fichier_path' => 'imports-produits/test.xlsx',
            'fichier_hash' => 'abc123',
            'statut' => 'termine',
            'termine_le' => now(),
        ]);

        $resultat = $this->parser->analyser(collect([$this->ligne()]), $this->org->id, 'hash-different');

        $this->assertNull($resultat['fichier_deja_importe']);
    }

    public function test_fichier_deja_importe_sans_creation_sans_sku_nest_pas_signale(): void
    {
        $produit = $this->creerProduitSimple();
        $sku = $produit->variantes->first()->sku;

        ImportProduits::create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'fichier_original' => 'test.xlsx',
            'fichier_path' => 'imports-produits/test.xlsx',
            'fichier_hash' => 'abc123',
            'statut' => 'termine',
            'termine_le' => now(),
        ]);

        // Fichier ré-importé ne contient QUE des mises à jour (SKU déjà connu) : jamais bloqué.
        $resultat = $this->parser->analyser(collect([$this->ligne(['sku' => $sku, 'nom' => ''])]), $this->org->id, 'abc123');

        $this->assertNull($resultat['fichier_deja_importe']);
    }
}
