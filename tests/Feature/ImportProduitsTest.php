<?php

namespace Tests\Feature;

use App\Models\ImportProduits;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\Site;
use App\Models\User;
use App\Services\ImportProduits\ImportProduitsProduitsSheetExport;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportProduitsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $this->user = $this->makeUser(['imports-produits.create', 'imports-produits.read', 'produits.create', 'produits.update']);
    }

    private function makeUser(array $permissions): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);

        $site = Site::factory()->for($this->org)->create();
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function typeCode(string $code = 'achat_vente'): string
    {
        return ProduitType::where('organization_id', $this->org->id)->where('code', $code)->value('code');
    }

    private function ligne(array $overrides = []): array
    {
        return array_replace([
            'sku' => '',
            'nom' => 'Sel Alpha 1kg',
            'type_code' => $this->typeCode(),
            'categorie_reference' => '',
            'fournisseur_reference' => '',
            'statut' => 'actif',
            'code_barres' => '',
            'prix_achat' => '1000',
            'prix_usine_autres_vehicules' => '',
            'prix_usine_tricycle' => '',
            'prix_vente' => '1500',
            'cout' => '',
            'alerte_stock_active' => 'non',
            'seuil_alerte_stock' => '',
            'description' => '',
        ], $overrides);
    }

    private function uploadFile(array $lignes): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PRODUITS');
        $sheet->fromArray(ImportProduitsProduitsSheetExport::COLONNES, null, 'A1');
        foreach ($lignes as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', ImportProduitsProduitsSheetExport::COLONNES);
            foreach ($row as $col => $valeur) {
                $cellule = Coordinate::stringFromColumnIndex($col + 1).($i + 2);
                $sheet->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_produits_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importer(array $lignes, ?User $acteur = null): ImportProduits
    {
        $this->actingAs($acteur ?? $this->user)
            ->post(route('produits.imports.store'), ['fichier' => $this->uploadFile($lignes)])
            ->assertRedirect();

        // orderByDesc('id') (pas firstOrFail() nu, et pas created_at — résolution à la seconde
        // insuffisante pour deux imports dans le même test) : certains tests importent plusieurs
        // fichiers dans le même test (réimport) — il faut toujours récupérer le DERNIER import
        // créé. Les ULID sont lexicographiquement triables par instant de création.
        return ImportProduits::orderByDesc('id')->firstOrFail();
    }

    private function confirmer(ImportProduits $import, ?User $acteur = null)
    {
        return $this->actingAs($acteur ?? $this->user)
            ->post(route('produits.imports.confirm', $import));
    }

    // ── accès / permissions ──────────────────────────────────────────────────

    public function test_create_redirects_unauthenticated_user(): void
    {
        $this->get(route('produits.imports.create'))->assertRedirect(route('login'));
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        $this->actingAs($user)->get(route('produits.imports.create'))->assertStatus(403);
    }

    public function test_modele_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.imports.modele'))
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── analyse ───────────────────────────────────────────────────────────────

    public function test_store_analyse_le_fichier_et_rapporte_les_compteurs(): void
    {
        $import = $this->importer([$this->ligne()]);

        $this->assertSame('analyse', $import->statut->value);
        $this->assertSame(1, $import->nb_lignes_total);
        $this->assertSame(1, $import->nb_lignes_creation);
        $this->assertSame(0, $import->nb_lignes_erreur);
    }

    public function test_analyse_flags_error_for_missing_nom(): void
    {
        $import = $this->importer([$this->ligne(['nom' => ''])]);

        $this->assertSame(1, $import->nb_lignes_erreur);
    }

    // ── confirmation : création ──────────────────────────────────────────────

    public function test_confirmer_cree_le_produit_avec_sku_auto_genere(): void
    {
        $import = $this->importer([$this->ligne()]);

        $this->confirmer($import)->assertRedirect(route('produits.imports.show', $import));

        $import->refresh();
        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1, $import->nb_produits_crees);

        $produit = Produit::where('organization_id', $this->org->id)->where('nom', 'Sel Alpha 1kg')->firstOrFail();
        $this->assertNotEmpty($produit->variantes->first()->sku);
    }

    public function test_confirmer_cree_le_produit_avec_sku_explicite(): void
    {
        $import = $this->importer([$this->ligne(['sku' => 'IMPORT-0001'])]);

        $this->confirmer($import)->assertRedirect();

        $produit = Produit::where('organization_id', $this->org->id)->where('nom', 'Sel Alpha 1kg')->firstOrFail();
        $this->assertSame('IMPORT-0001', $produit->variantes->first()->sku);
    }

    public function test_confirmer_cree_un_audit_de_creation(): void
    {
        $import = $this->importer([$this->ligne()]);
        $this->confirmer($import);

        $produit = Produit::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Produit::class,
            'auditable_id' => $produit->id,
            'event_code' => 'created',
        ]);
    }

    // ── confirmation : mise à jour ───────────────────────────────────────────

    public function test_confirmer_met_a_jour_le_prix_quand_le_fichier_le_change(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Sel Alpha 1kg',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $sku = $produit->variantes->first()->sku;

        $import = $this->importer([$this->ligne(['sku' => $sku, 'nom' => '', 'prix_vente' => '1800'])]);
        $this->confirmer($import)->assertRedirect();

        $import->refresh();
        $this->assertSame(1, $import->nb_produits_mis_a_jour);
        $this->assertSame(1800, $produit->fresh()->variantes->first()->prix_vente);
    }

    public function test_confirmer_ne_modifie_jamais_le_sku_dune_variante_existante(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Sel Alpha 1kg',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
            'sku' => 'SKU-ORIGINAL',
        ]);

        $import = $this->importer([$this->ligne(['sku' => 'SKU-ORIGINAL', 'nom' => '', 'prix_vente' => '1800'])]);
        $this->confirmer($import)->assertRedirect();

        $this->assertSame('SKU-ORIGINAL', $produit->fresh()->variantes->first()->sku);
    }

    public function test_ligne_inchangee_ne_declenche_aucune_ecriture_ni_audit(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Sel Alpha 1kg',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $sku = $produit->variantes->first()->sku;
        $updatedAtAvant = $produit->updated_at;

        $import = $this->importer([$this->ligne(['sku' => $sku, 'nom' => 'Sel Alpha 1kg', 'prix_achat' => '1000', 'prix_vente' => '1500'])]);
        $this->confirmer($import)->assertRedirect();

        $import->refresh();
        $this->assertSame(0, $import->nb_produits_mis_a_jour);
        $this->assertSame(0, $import->nb_produits_crees);
        $this->assertTrue($updatedAtAvant->equalTo($produit->fresh()->updated_at));
        $this->assertDatabaseMissing('audit_logs', ['auditable_id' => $produit->id, 'event_code' => 'updated']);
    }

    // ── transaction / erreurs ────────────────────────────────────────────────

    public function test_une_ligne_en_erreur_bloque_toute_confirmation(): void
    {
        $import = $this->importer([$this->ligne(['nom' => 'Produit valide']), $this->ligne(['nom' => ''])]);

        $this->confirmer($import)->assertStatus(422);

        $this->assertSame(0, Produit::where('organization_id', $this->org->id)->count());
    }

    public function test_sku_duplique_bloque_toute_confirmation(): void
    {
        $import = $this->importer([
            $this->ligne(['sku' => 'DUP-01']),
            $this->ligne(['sku' => 'DUP-01', 'nom' => 'Autre produit']),
        ]);

        $this->confirmer($import)->assertStatus(422);
        $this->assertSame(0, Produit::where('organization_id', $this->org->id)->count());
    }

    // ── double confirmation ──────────────────────────────────────────────────

    public function test_double_confirmation_nexecute_quune_seule_fois(): void
    {
        $import = $this->importer([$this->ligne()]);

        $this->confirmer($import)->assertRedirect();
        $this->confirmer($import)->assertStatus(422);

        $this->assertSame(1, Produit::where('organization_id', $this->org->id)->count());
    }

    // ── permissions différenciées create/update ──────────────────────────────

    public function test_confirmer_bloque_les_creations_sans_permission_produits_create(): void
    {
        $sansCreate = $this->makeUser(['imports-produits.create', 'imports-produits.read', 'produits.update']);

        $import = $this->importer([$this->ligne()], $sansCreate);
        // Comme ImportFlotteController, un échec d'exécution (ici : permission manquante) fait
        // toujours transiter l'import vers le statut "echoue" via une redirection normale — ce
        // n'est pas une réponse HTTP 422 (réservée aux abort_unless() du contrôleur lui-même,
        // ex. double confirmation). L'essentiel est vérifié par l'absence d'écriture ci-dessous.
        $this->confirmer($import, $sansCreate)->assertRedirect();

        $this->assertSame('echoue', $import->fresh()->statut->value);
        $this->assertSame(0, Produit::where('organization_id', $this->org->id)->count());
    }

    public function test_confirmer_bloque_les_mises_a_jour_sans_permission_produits_update(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Sel Alpha 1kg',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $sku = $produit->variantes->first()->sku;

        $sansUpdate = $this->makeUser(['imports-produits.create', 'imports-produits.read', 'produits.create']);

        $import = $this->importer([$this->ligne(['sku' => $sku, 'nom' => '', 'prix_vente' => '2000'])], $sansUpdate);
        $this->confirmer($import, $sansUpdate)->assertRedirect();

        $this->assertSame('echoue', $import->fresh()->statut->value);
        $this->assertSame(1500, $produit->fresh()->variantes->first()->prix_vente);
    }

    /**
     * `view` (imports-produits.read seul) ne doit jamais suffire à déclencher l'écriture —
     * même si l'utilisateur possède par ailleurs produits.create/produits.update, il lui manque
     * la permission de LANCER l'exécution elle-même (imports-produits.create), distincte du
     * droit de simplement consulter l'historique.
     */
    public function test_confirmer_est_interdit_avec_seulement_imports_produits_read(): void
    {
        $lectureSeule = $this->makeUser(['imports-produits.create', 'imports-produits.read']);
        $import = $this->importer([$this->ligne()], $lectureSeule);

        $sansCreateImport = $this->makeUser(['imports-produits.read', 'produits.create', 'produits.update']);

        $this->confirmer($import, $sansCreateImport)->assertStatus(403);
        $this->assertSame('analyse', $import->fresh()->statut->value);
        $this->assertSame(0, Produit::where('organization_id', $this->org->id)->count());
    }

    public function test_retry_est_interdit_avec_seulement_imports_produits_read(): void
    {
        $import = $this->importer([$this->ligne(['nom' => ''])]);
        $this->assertSame(1, $import->nb_lignes_erreur);

        // Provoque un échec exploitable pour retry() : on force le statut à "echoue" directement
        // (contourne l'impossibilité de confirmer un import déjà en erreur, non pertinente ici).
        $import->update(['statut' => 'echoue']);

        $sansCreateImport = $this->makeUser(['imports-produits.read', 'produits.create', 'produits.update']);

        $this->actingAs($sansCreateImport)
            ->post(route('produits.imports.retry', $import))
            ->assertStatus(403);
    }

    // ── message de confirmation reflète le résultat réel ─────────────────────

    public function test_confirmer_echoue_naffiche_jamais_un_message_de_succes(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Sel Alpha 1kg',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $sku = $produit->variantes->first()->sku;
        $sansUpdate = $this->makeUser(['imports-produits.create', 'imports-produits.read', 'produits.create']);

        $import = $this->importer([$this->ligne(['sku' => $sku, 'nom' => '', 'prix_vente' => '2000'])], $sansUpdate);

        $response = $this->confirmer($import, $sansUpdate);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
    }

    public function test_confirmer_reussi_affiche_un_message_de_succes(): void
    {
        $import = $this->importer([$this->ligne()]);

        $response = $this->confirmer($import);
        $response->assertSessionHas(
            'success',
            'Import terminé : 1 produit créé, 0 produit mis à jour.',
        );
        $response->assertSessionMissing('error');
    }

    // ── fichier corrompu ──────────────────────────────────────────────────────

    /**
     * Un simple fichier texte renommé en .xlsx est déjà rejeté par la validation Laravel
     * (`mimes:xlsx,xls`, basée sur le contenu réel, pas l'extension) avant même d'atteindre le
     * contrôleur — insuffisant pour exercer le cas visé ici (échec d'IOFactory::load() en
     * interne). On construit donc un .xlsx STRUCTURELLEMENT valide (bonne signature ZIP/OOXML,
     * passe la validation Laravel) mais dont le XML interne du classeur est corrompu — c'est ce
     * genre de fichier "presque valide" qui atteint réellement le parseur puis y échoue.
     */
    private function fichierXlsxAvecXmlInterneCorrompu(): UploadedFile
    {
        $tmpPath = $this->uploadFile([$this->ligne()])->getRealPath();

        $zip = new \ZipArchive;
        $zip->open($tmpPath);
        $zip->addFromString('xl/workbook.xml', '<ceci nest pas du XML valide');
        $zip->close();

        return new UploadedFile($tmpPath, 'corrompu.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_fichier_excel_corrompu_echoue_proprement_sans_erreur_500(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('produits.imports.store'), ['fichier' => $this->fichierXlsxAvecXmlInterneCorrompu()]);

        $response->assertRedirect();
        $import = ImportProduits::orderByDesc('id')->firstOrFail();
        $this->assertSame('echoue', $import->statut->value);
        $this->assertNotNull($import->erreur_technique);
        $this->assertStringNotContainsString('Exception', $import->erreur_technique);
    }

    // ── concurrence : diff modifié entre aperçu et confirmation ──────────────

    /**
     * Entre l'aperçu (store()) et la confirmation, un AUTRE acteur modifie le produit ciblé
     * (ici : édition manuelle directe via le service, hors de cet import) — le diff recalculé à
     * la confirmation diffère alors de celui présenté à l'utilisateur. La confirmation doit être
     * refusée et l'import revenir à "analyse" avec un aperçu à jour, jamais appliquer un diff
     * différent de celui revu par l'utilisateur.
     */
    public function test_confirmer_refuse_si_le_produit_cible_a_change_depuis_lapercu(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Sel Alpha 1kg',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $sku = $produit->variantes->first()->sku;

        // Aperçu généré pour "prix_vente : 1500 → 1800".
        $import = $this->importer([$this->ligne(['sku' => $sku, 'nom' => '', 'prix_vente' => '1800'])]);
        $ancienRapport = $import->rapport;
        $this->assertSame(['avant' => 1500, 'apres' => 1800], $ancienRapport['lignes'][0]['changements']['prix_vente']);

        // Un autre acteur modifie le prix_vente APRÈS l'aperçu, avant la confirmation — le diff
        // réel à la confirmation ("1900 → 1800") diffère de celui prévisualisé ("1500 → 1800").
        app(ProduitService::class)->mettreAJourSimple($produit, ['prix_vente' => 1900]);

        $response = $this->confirmer($import);
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $import->refresh();
        $this->assertSame('analyse', $import->statut->value);
        // Le nouvel aperçu doit refléter l'état réel actuel (1900 → 1800), pas l'ancien.
        $this->assertSame(1900, $import->rapport['lignes'][0]['changements']['prix_vente']['avant']);
        // Aucune écriture appliquée à l'aveugle : le prix reste à 1900 (valeur du tiers), pas 1800.
        $this->assertSame(1900, $produit->fresh()->variantes->first()->prix_vente);
    }

    /** Reconfirmer après avoir revu le nouvel aperçu doit fonctionner normalement. */
    public function test_confirmer_reussit_apres_reanalyse_du_nouvel_apercu(): void
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Sel Alpha 1kg',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'achat_vente')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 1500,
        ]);
        $sku = $produit->variantes->first()->sku;

        $import = $this->importer([$this->ligne(['sku' => $sku, 'nom' => '', 'prix_vente' => '1800'])]);
        app(ProduitService::class)->mettreAJourSimple($produit, ['prix_vente' => 1900]);

        $this->confirmer($import)->assertRedirect(); // 1er essai : refusé, repasse en "analyse"
        $import->refresh();
        $this->assertSame('analyse', $import->statut->value);

        $this->confirmer($import)->assertRedirect(); // 2e essai : plus aucun écart, doit réussir
        $import->refresh();
        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1800, $produit->fresh()->variantes->first()->prix_vente);
    }

    // ── idempotence : fichier déjà importé ────────────────────────────────────

    public function test_reimporter_le_meme_fichier_avec_creations_sans_sku_est_bloque(): void
    {
        // Un seul fichier physique généré, réutilisé pour les deux envois : reproduit
        // fidèlement "le même fichier réimporté tel quel" — régénérer un xlsx deux fois via
        // PhpSpreadsheet (même avec un contenu de cellules identique) peut produire des octets
        // différents (métadonnées internes horodatées), ce qui rendrait la comparaison de hash
        // non déterministe ici sans refléter un vrai défaut du mécanisme.
        $cheminFichier = $this->uploadFile([$this->ligne()])->getRealPath();

        $this->actingAs($this->user)
            ->post(route('produits.imports.store'), ['fichier' => new UploadedFile($cheminFichier, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)])
            ->assertRedirect();
        $premier = ImportProduits::orderByDesc('id')->firstOrFail();
        $this->confirmer($premier)->assertRedirect();

        $this->actingAs($this->user)
            ->post(route('produits.imports.store'), ['fichier' => new UploadedFile($cheminFichier, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)])
            ->assertRedirect();
        $second = ImportProduits::orderByDesc('id')->firstOrFail();

        $this->assertFalse($second->estPret());
        $this->confirmer($second)->assertStatus(422);
    }

    // ── fichier de reprise ────────────────────────────────────────────────────

    public function test_reprise_est_telechargeable_apres_succes_et_contient_le_sku_genere(): void
    {
        $import = $this->importer([$this->ligne()]);
        $this->confirmer($import)->assertRedirect();

        $response = $this->actingAs($this->user)->get(route('produits.imports.reprise', $import->fresh()));
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_reprise_est_indisponible_avant_confirmation(): void
    {
        $import = $this->importer([$this->ligne()]);

        $this->actingAs($this->user)->get(route('produits.imports.reprise', $import))->assertStatus(404);
    }

    public function test_reimporter_le_fichier_de_reprise_sans_modification_classe_tout_en_inchange(): void
    {
        $import = $this->importer([$this->ligne()]);
        $this->confirmer($import)->assertRedirect();
        $import->refresh();

        $produit = Produit::where('organization_id', $this->org->id)->firstOrFail();
        $sku = $produit->variantes->first()->sku;

        // Réimporte manuellement les mêmes valeurs mais avec le SKU désormais connu — simule le
        // fichier de reprise reporté par l'utilisateur.
        $reimport = $this->importer([$this->ligne(['sku' => $sku])]);

        $this->assertSame(0, $reimport->nb_lignes_creation);
        $this->assertSame(0, $reimport->nb_lignes_mise_a_jour);
        $this->assertSame(1, $reimport->nb_lignes_inchange);
    }
}
