<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\ImportProduits;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Couvre le modèle Excel téléchargeable et le fichier de reprise : 4 onglets, ordre des
 * colonnes, isolation multi-organisation — cf. plan squishy-launching-mochi §"Plan de tests".
 */
class ImportProduitsExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach (['imports-produits.create', 'imports-produits.read', 'produits.create', 'produits.update'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->assignRole('admin_entreprise');
        $this->user->givePermissionTo(['imports-produits.create', 'imports-produits.read', 'produits.create', 'produits.update']);
        $site = Site::factory()->for($this->org)->create();
        $this->user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function telechargerEtOuvrir(string $route, array $params = []): Spreadsheet
    {
        $response = $this->actingAs($this->user)->get(route($route, $params));
        $response->assertStatus(200);

        $tmpPath = tempnam(sys_get_temp_dir(), 'export_test').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());

        return IOFactory::load($tmpPath);
    }

    public function test_modele_contient_les_4_onglets_dans_lordre(): void
    {
        $spreadsheet = $this->telechargerEtOuvrir('produits.imports.modele');

        $this->assertSame(
            ['MODE_EMPLOI', 'PRODUITS', 'REFERENCES', 'EXEMPLES'],
            $spreadsheet->getSheetNames(),
        );
    }

    public function test_modele_onglet_produits_a_lordre_exact_des_colonnes(): void
    {
        $spreadsheet = $this->telechargerEtOuvrir('produits.imports.modele');
        $sheet = $spreadsheet->getSheetByName('PRODUITS');
        $entetes = $sheet->toArray(null, true, true, false)[0];

        $this->assertSame([
            'sku', 'nom', 'type_code', 'categorie_reference', 'fournisseur_reference', 'statut',
            'code_barres', 'prix_achat', 'prix_usine_autres_vehicules', 'prix_usine_tricycle', 'prix_vente',
            'prix_externe', 'prix_revendeur', 'prix_distributeur', 'cout',
            'alerte_stock_active', 'seuil_alerte_stock', 'description',
        ], $entetes);
        $this->assertSame(32.0, $sheet->getColumnDimension('I')->getWidth());
        $this->assertSame(24.0, $sheet->getColumnDimension('J')->getWidth());
    }

    public function test_modele_documente_les_deux_prix_usine_sans_ambiguite(): void
    {
        $spreadsheet = $this->telechargerEtOuvrir('produits.imports.modele');
        $texte = collect(['MODE_EMPLOI', 'REFERENCES'])
            ->flatMap(fn (string $nom) => $spreadsheet->getSheetByName($nom)->toArray())
            ->flatten()
            ->filter(fn ($valeur) => is_string($valeur))
            ->implode(' ');

        $this->assertStringContainsString('Prix usine — Autres véhicules', $texte);
        $this->assertStringContainsString('Prix usine — Tricycle', $texte);
    }

    public function test_modele_onglet_produits_ne_contient_aucune_ligne_dexemple(): void
    {
        $spreadsheet = $this->telechargerEtOuvrir('produits.imports.modele');
        $sheet = $spreadsheet->getSheetByName('PRODUITS');
        // getHighestRow() inclut les lignes 2-501 : elles portent des styles/validations Excel
        // (format Texte, listes déroulantes) mais aucune VALEUR — c'est le contenu qui compte ici.
        $tableau = $sheet->toArray(null, true, true, false);

        $this->assertCount(1, array_filter($tableau, fn (array $row) => array_filter($row, fn ($v) => trim((string) $v) !== '') !== []));
    }

    public function test_modele_references_est_scope_a_lorganisation(): void
    {
        $categorieMoi = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Ma categorie']);

        $autreOrg = Organization::factory()->create();
        Categorie::create(['organization_id' => $autreOrg->id, 'nom' => 'Categorie interdite']);

        $spreadsheet = $this->telechargerEtOuvrir('produits.imports.modele');
        $sheet = $spreadsheet->getSheetByName('REFERENCES');
        $texte = implode(' ', array_map(fn ($row) => implode(' ', array_filter($row, 'is_string')), $sheet->toArray()));

        $this->assertStringContainsString($categorieMoi->reference, $texte);
        $this->assertStringNotContainsString('Categorie interdite', $texte);
    }

    public function test_modele_returns_403_without_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        $this->actingAs($user)->get(route('produits.imports.modele'))->assertStatus(403);
    }

    // ── fichier de reprise ────────────────────────────────────────────────────

    private function importerEtConfirmer(): ImportProduits
    {
        Storage::disk('local')->makeDirectory('imports-produits');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PRODUITS');
        $headers = ['sku', 'nom', 'type_code', 'categorie_reference', 'fournisseur_reference', 'statut', 'code_barres', 'prix_achat', 'prix_usine_autres_vehicules', 'prix_usine_tricycle', 'prix_vente', 'cout', 'alerte_stock_active', 'seuil_alerte_stock', 'description'];
        $sheet->fromArray($headers, null, 'A1');
        $row = ['', 'Sel Alpha 1kg', 'achat_vente', '', '', 'actif', '', '1000', '', '', '1500', '', 'non', '', ''];
        foreach ($row as $col => $valeur) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($col + 1).'2', (string) $valeur, DataType::TYPE_STRING);
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'reprise_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $fichier = new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->user)->post(route('produits.imports.store'), ['fichier' => $fichier])->assertRedirect();
        $import = ImportProduits::orderByDesc('id')->firstOrFail();
        $this->actingAs($this->user)->post(route('produits.imports.confirm', $import))->assertRedirect();

        return $import->fresh();
    }

    public function test_reprise_contient_le_sku_genere_et_4_onglets(): void
    {
        $import = $this->importerEtConfirmer();

        $spreadsheet = $this->telechargerEtOuvrir('produits.imports.reprise', ['importProduits' => $import->id]);

        $this->assertSame(['MODE_EMPLOI', 'PRODUITS', 'REFERENCES', 'RESULTATS'], $spreadsheet->getSheetNames());

        $sheet = $spreadsheet->getSheetByName('PRODUITS');
        $ligne = $sheet->toArray(null, true, true, false)[1];
        $this->assertNotEmpty($ligne[0], 'le SKU généré doit être renseigné dans le fichier de reprise');
        $this->assertSame('Sel Alpha 1kg', $ligne[1]);
    }

    /**
     * Garde-fou contre un décalage positionnel : ImportProduitsRepriseProduitsSheetExport
     * construit chaque ligne dans un tableau ordonné à la main (pas par nom d'en-tête) — si les 3
     * colonnes de tarification par nature ne sont pas insérées au bon endroit, prix_externe
     * afficherait en réalité la valeur de prix_vente/cout d'un autre champ.
     */
    public function test_reprise_aligne_correctement_les_prix_par_nature_pour_un_produit_fabricable(): void
    {
        Storage::disk('local')->makeDirectory('imports-produits');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PRODUITS');
        $headers = ['sku', 'nom', 'type_code', 'categorie_reference', 'fournisseur_reference', 'statut', 'code_barres', 'prix_achat', 'prix_usine_autres_vehicules', 'prix_usine_tricycle', 'prix_vente', 'prix_externe', 'prix_revendeur', 'prix_distributeur', 'cout', 'alerte_stock_active', 'seuil_alerte_stock', 'description'];
        $sheet->fromArray($headers, null, 'A1');
        $row = ['', 'Bidon Fabricable', 'fabricable', '', '', 'actif', '', '', '18000', '18000', '20000', '18250', '19000', '18500', '', 'non', '', ''];
        foreach ($row as $col => $valeur) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($col + 1).'2', (string) $valeur, DataType::TYPE_STRING);
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'reprise_fabricable_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $fichier = new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->user)->post(route('produits.imports.store'), ['fichier' => $fichier])->assertRedirect();
        $import = ImportProduits::orderByDesc('id')->firstOrFail();
        $this->actingAs($this->user)->post(route('produits.imports.confirm', $import))->assertRedirect();

        $spreadsheet = $this->telechargerEtOuvrir('produits.imports.reprise', ['importProduits' => $import->fresh()->id]);
        $sheet = $spreadsheet->getSheetByName('PRODUITS');
        $tableau = $sheet->toArray(null, true, true, false);
        $donnees = array_combine($tableau[0], $tableau[1]);

        $this->assertEquals(18250, $donnees['prix_externe']);
        $this->assertEquals(19000, $donnees['prix_revendeur']);
        $this->assertEquals(18500, $donnees['prix_distributeur']);
        $this->assertEquals(20000, $donnees['prix_vente']);
        $this->assertEquals(18000, $donnees['prix_usine_autres_vehicules']);
    }

    public function test_reprise_est_interdite_a_une_autre_organisation(): void
    {
        $import = $this->importerEtConfirmer();

        $autreOrg = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $autreUser = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autreUser->assignRole('admin_entreprise');
        $autreUser->givePermissionTo(['imports-produits.read']);
        $siteAutre = Site::factory()->for($autreOrg)->create();
        $autreUser->sites()->attach($siteAutre->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($autreUser)->get(route('produits.imports.reprise', $import))->assertStatus(403);
    }
}
