<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class SiteImportTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const HEADERS = [
        'nom', 'type', 'ville_obligatoire', 'quartier_obligatoire', 'telephone_obligatoire',
        'description_facultatif', 'site_parent_facultatif', 'longitude_facultatif', 'latitude_facultatif',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['sites.read', 'sites.create', 'sites.update', 'sites.delete']);
    }

    private function ligne(array $overrides = []): array
    {
        return array_replace([
            'nom' => 'Matoto',
            'type' => 'Siège',
            'ville_obligatoire' => 'Conakry',
            'quartier_obligatoire' => 'Matoto',
            'telephone_obligatoire' => '+224664039160',
            'description_facultatif' => '',
            'site_parent_facultatif' => '',
            'longitude_facultatif' => '',
            'latitude_facultatif' => '',
        ], $overrides);
    }

    private function uploadFile(array $lignes): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('sites');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        foreach ($lignes as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', self::HEADERS);
            $sheet->fromArray($row, null, 'A'.($i + 2));
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_sites_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function analyser(array $lignes): array
    {
        return $this->actingAs($this->user)
            ->post(route('sites.import.analyser'), ['fichier' => $this->uploadFile($lignes)], ['Accept' => 'application/json'])
            ->json();
    }

    private function confirmer(array $lignes): array
    {
        return $this->actingAs($this->user)
            ->post(route('sites.import.confirmer'), ['fichier' => $this->uploadFile($lignes)], ['Accept' => 'application/json'])
            ->json();
    }

    // ── permissions / accès ──────────────────────────────────────────────────

    public function test_modele_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.import.modele'))
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_modele_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('sites.import.modele'))
            ->assertStatus(403);
    }

    public function test_analyser_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->post(route('sites.import.analyser'), ['fichier' => $this->uploadFile([$this->ligne()])], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_confirmer_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->post(route('sites.import.confirmer'), ['fichier' => $this->uploadFile([$this->ligne()])], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_analyser_redirects_unauthenticated_user(): void
    {
        $this->post(route('sites.import.analyser'), ['fichier' => $this->uploadFile([$this->ligne()])])
            ->assertRedirect(route('login'));
    }

    // ── analyse ──────────────────────────────────────────────────────────────

    public function test_analyser_valid_file_reports_counts(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Depot Un', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertSame(1, $json['nb_lignes_total']);
        $this->assertSame(1, $json['nb_nouveaux']);
        $this->assertSame(0, $json['nb_existants']);
        $this->assertSame(0, $json['nb_erreurs']);
    }

    public function test_analyser_flags_missing_required_fields(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => '', 'ville_obligatoire' => '', 'quartier_obligatoire' => '', 'telephone_obligatoire' => '']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $erreurs = $json['lignes'][0]['erreurs'];
        $this->assertStringContainsString('`nom`', $erreurs[0]);
    }

    public function test_analyser_flags_invalid_type(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Depot X', 'type' => 'MagasinX']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertStringContainsString('`type`', $json['lignes'][0]['erreurs'][0]);
        $this->assertStringContainsString('MagasinX', $json['lignes'][0]['erreurs'][0]);
    }

    public function test_analyser_flags_invalid_phone(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Depot Y', 'telephone_obligatoire' => '123']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertStringContainsString('`telephone_obligatoire`', $json['lignes'][0]['erreurs'][0]);
    }

    public function test_analyser_flags_invalid_coordinates(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Depot Z', 'longitude_facultatif' => '999', 'latitude_facultatif' => 'abc']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertCount(2, $json['lignes'][0]['erreurs']);
    }

    public function test_analyser_accepts_valid_coordinates(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Depot Coord', 'longitude_facultatif' => '-13.6773', 'latitude_facultatif' => '9.5370']),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
    }

    public function test_analyser_detects_existing_parent_in_db(): void
    {
        Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto']);

        $json = $this->analyser([
            $this->ligne(['nom' => 'Cba', 'type' => 'Usine', 'site_parent_facultatif' => 'Matoto', 'telephone_obligatoire' => '+224626078393']),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
    }

    public function test_analyser_resolves_parent_present_in_same_file(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Matoto', 'telephone_obligatoire' => '+224664039160']),
            $this->ligne(['nom' => 'Cba', 'type' => 'Usine', 'site_parent_facultatif' => 'Matoto', 'telephone_obligatoire' => '+224626078393']),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
        $this->assertSame(2, $json['nb_nouveaux']);
    }

    public function test_analyser_flags_unknown_parent(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Cba', 'type' => 'Usine', 'site_parent_facultatif' => 'Introuvable', 'telephone_obligatoire' => '+224626078393']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertStringContainsString('site_parent_facultatif', $json['lignes'][0]['erreurs'][0]);
        $this->assertStringContainsString('Introuvable', $json['lignes'][0]['erreurs'][0]);
    }

    public function test_analyser_flags_duplicate_nom_in_file(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Doublon', 'telephone_obligatoire' => '+224622671016']),
            $this->ligne(['nom' => 'Doublon', 'telephone_obligatoire' => '+224622671017']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertSame(1, $json['nb_nouveaux']);
    }

    public function test_analyser_detects_existing_site_by_nom_case_insensitive(): void
    {
        Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto']);

        $json = $this->analyser([
            $this->ligne(['nom' => 'MATOTO']),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
        $this->assertSame(0, $json['nb_nouveaux']);
        $this->assertSame(1, $json['nb_existants']);
        $this->assertSame('existant', $json['lignes'][0]['statut']);
    }

    public function test_analyser_is_scoped_to_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Matoto', 'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto']);

        // Même nom mais dans une autre organisation : ne doit pas être détecté
        // comme existant côté organisation courante.
        $json = $this->analyser([
            $this->ligne(['nom' => 'Matoto']),
        ]);

        $this->assertSame(1, $json['nb_nouveaux']);
        $this->assertSame(0, $json['nb_existants']);
    }

    public function test_analyser_mixes_valid_and_invalid_lines(): void
    {
        $json = $this->analyser([
            $this->ligne(['nom' => 'Bon Site', 'telephone_obligatoire' => '+224622671016']),
            $this->ligne(['nom' => 'Mauvais Site', 'type' => 'Inconnu']),
        ]);

        $this->assertSame(2, $json['nb_lignes_total']);
        $this->assertSame(1, $json['nb_nouveaux']);
        $this->assertSame(1, $json['nb_erreurs']);
    }

    // ── confirmation ─────────────────────────────────────────────────────────

    public function test_confirmer_creates_sites_and_resolves_parent_from_same_file(): void
    {
        $json = $this->confirmer([
            $this->ligne(['nom' => 'Matoto', 'telephone_obligatoire' => '+224664039160']),
            $this->ligne(['nom' => 'Cba', 'type' => 'Usine', 'site_parent_facultatif' => 'Matoto', 'telephone_obligatoire' => '+224626078393']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(2, $json['crees']);
        $this->assertSame(0, $json['existants_ignores']);

        $matoto = Site::where('organization_id', $this->org->id)->where('nom', 'Matoto')->firstOrFail();
        $cba = Site::where('organization_id', $this->org->id)->where('nom', 'Cba')->firstOrFail();
        $this->assertSame($matoto->id, $cba->parent_id);
        $this->assertSame('usine', $cba->type->value);
    }

    public function test_confirmer_does_not_recreate_or_modify_existing_site(): void
    {
        $existant = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Matoto',
            'type' => 'depot',
            'ville' => 'Kindia',
            'quartier' => 'Centre',
        ]);

        $json = $this->confirmer([
            $this->ligne(['nom' => 'Matoto', 'type' => 'Siège', 'ville_obligatoire' => 'Conakry']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(0, $json['crees']);
        $this->assertSame(1, $json['existants_ignores']);

        $existant->refresh();
        $this->assertSame('depot', $existant->type->value);
        $this->assertSame('Kindia', $existant->ville);
    }

    public function test_confirmer_rejects_when_errors_present_and_creates_nothing(): void
    {
        $json = $this->confirmer([
            $this->ligne(['nom' => 'Bon Site', 'telephone_obligatoire' => '+224622671016']),
            $this->ligne(['nom' => 'Mauvais Site', 'type' => 'Inconnu']),
        ]);

        $this->assertFalse($json['execute']);
        $this->assertDatabaseMissing('sites', ['organization_id' => $this->org->id, 'nom' => 'Bon Site']);
        $this->assertDatabaseMissing('sites', ['organization_id' => $this->org->id, 'nom' => 'Mauvais Site']);
    }

    public function test_confirmer_is_scoped_to_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Matoto', 'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto']);

        $json = $this->confirmer([
            $this->ligne(['nom' => 'Matoto']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(1, $json['crees']);
        $this->assertSame(2, Site::where('nom', 'Matoto')->count());
    }
}
