<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class SiteImportTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const HEADERS = [
        'nom', 'code_facultatif', 'type', 'ville_obligatoire', 'quartier_obligatoire', 'telephone_obligatoire',
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
            'code_facultatif' => '',
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
            // Écriture cellule par cellule en type texte explicite plutôt que
            // fromArray() : le DefaultValueBinder de PhpSpreadsheet considère
            // qu'une chaîne comme "001" est numérique (is_numeric('001') est
            // vrai en PHP) et la convertirait silencieusement en nombre 1,
            // perdant les zéros initiaux — exactement le piège que le code
            // d'import doit justement éviter (cf. SiteImportParser).
            foreach ($row as $col => $valeur) {
                $cellule = Coordinate::stringFromColumnIndex($col + 1).($i + 2);
                $sheet->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
            }
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

    // ── code : création ou mise à jour ──────────────────────────────────────
    //
    // NB : `initOrgAndUser()` (HasOrgAndUser) crée déjà un site par défaut
    // ("Site Principal") pour $this->org, qui capte automatiquement le code
    // "001" (premier site de l'organisation, cf. Site::boot()). Les fixtures
    // ci-dessous utilisent donc délibérément d'autres codes (101, 102, 025…)
    // pour ne pas entrer en collision avec ce site déjà existant — un test
    // dédié (test_code_avec_zero_initial_est_preserve_apres_import) couvre
    // spécifiquement la préservation des zéros initiaux.

    public function test_analyser_reports_ligne_as_mise_a_jour_when_code_matches_existing_site(): void
    {
        Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
        ]);

        $json = $this->analyser([
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Matoto Centre', 'type' => 'Agence']),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
        $this->assertSame(0, $json['nb_nouveaux']);
        $this->assertSame(1, $json['nb_mises_a_jour']);
        $this->assertSame('mise_a_jour', $json['lignes'][0]['statut']);
    }

    public function test_confirmer_matches_existing_code_even_without_leading_zeros(): void
    {
        // Cas observé en usage réel : un utilisateur qui retape un code
        // depuis un tableur (colonne "Nombre") saisit "7" au lieu de "007" —
        // le rapprochement doit quand même reconnaître le site existant,
        // cf. ReferenceValueResolver::normalizeNumericCode(), déjà utilisé
        // ailleurs dans le projet pour cette même tolérance sur les codes.
        $existant = Site::create([
            'organization_id' => $this->org->id, 'code' => '007', 'nom' => 'Tombolia',
            'type' => 'depot', 'ville' => 'Conakry', 'quartier' => 'Tombolia',
        ]);

        $json = $this->confirmer([
            $this->ligne(['code_facultatif' => '7', 'nom' => 'Tombolia', 'type' => 'Agence', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(0, $json['crees']);
        $this->assertSame(1, $json['mis_a_jour']);

        $existant->refresh();
        $this->assertSame('agence', $existant->type->value);
        // La valeur stockée n'est jamais réécrite par le rapprochement tolérant.
        $this->assertSame('007', $existant->code);
    }

    public function test_analyser_flags_duplicate_code_in_file_despite_different_zero_padding(): void
    {
        $json = $this->analyser([
            $this->ligne(['code_facultatif' => '007', 'nom' => 'Tombolia', 'telephone_obligatoire' => '+224622671016']),
            $this->ligne(['code_facultatif' => '7', 'nom' => 'Autre Site', 'telephone_obligatoire' => '+224622671017']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $erreur = collect($json['lignes'])->firstWhere('statut', 'erreur');
        $this->assertNotNull($erreur);
        $this->assertStringContainsString('code_facultatif', $erreur['erreurs'][0]);
    }

    public function test_confirmer_creates_site_with_explicit_code_when_code_not_in_db(): void
    {
        $json = $this->confirmer([
            $this->ligne(['code_facultatif' => '102', 'nom' => 'Madina', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(1, $json['crees']);
        $this->assertSame(0, $json['mis_a_jour']);

        $site = Site::where('organization_id', $this->org->id)->where('nom', 'Madina')->firstOrFail();
        $this->assertSame('102', $site->code);
    }

    public function test_confirmer_updates_existing_site_matched_by_code_same_organization(): void
    {
        $existant = Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
        ]);

        $json = $this->confirmer([
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Matoto Centre', 'type' => 'Agence', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(0, $json['crees']);
        $this->assertSame(1, $json['mis_a_jour']);

        $existant->refresh();
        $this->assertSame('Matoto Centre', $existant->nom);
        $this->assertSame('agence', $existant->type->value);
        $this->assertSame('101', $existant->code);
    }

    public function test_confirmer_by_code_leaves_exactly_one_site_with_that_code(): void
    {
        Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
        ]);

        $this->confirmer([
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Matoto Centre', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertSame(1, Site::where('organization_id', $this->org->id)->where('code', '101')->count());
    }

    public function test_confirmer_by_code_does_not_impact_another_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteAutreOrg = Site::create([
            'organization_id' => $autreOrg->id, 'code' => '101', 'nom' => 'Boutique Madina',
            'type' => 'boutique', 'ville' => 'Conakry', 'quartier' => 'Madina',
        ]);

        $json = $this->confirmer([
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Matoto', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(1, $json['crees']);
        $this->assertSame(0, $json['mis_a_jour']);

        $siteAutreOrg->refresh();
        $this->assertSame('Boutique Madina', $siteAutreOrg->nom);
        $this->assertSame(2, Site::where('code', '101')->count());
    }

    public function test_confirmer_by_code_updates_type(): void
    {
        $existant = Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
        ]);

        $this->confirmer([
            $this->ligne(['code_facultatif' => '101', 'type' => 'Usine', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $existant->refresh();
        $this->assertSame('usine', $existant->type->value);
    }

    public function test_confirmer_by_code_updates_ville_et_quartier(): void
    {
        $existant = Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
        ]);

        $this->confirmer([
            $this->ligne(['code_facultatif' => '101', 'ville_obligatoire' => 'Kindia', 'quartier_obligatoire' => 'Centre', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $existant->refresh();
        $this->assertSame('Kindia', $existant->ville);
        $this->assertSame('Centre', $existant->quartier);
    }

    public function test_code_avec_zero_initial_est_preserve_apres_import(): void
    {
        $json = $this->confirmer([
            $this->ligne(['code_facultatif' => '025', 'nom' => 'Sonfonia', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertTrue($json['execute']);
        $site = Site::where('organization_id', $this->org->id)->where('nom', 'Sonfonia')->firstOrFail();
        $this->assertSame('025', $site->code);
    }

    public function test_confirmer_by_code_does_not_erase_optional_field_left_empty(): void
    {
        $existant = Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
            'description' => 'Site historique', 'longitude' => -13.6773, 'latitude' => 9.5370,
        ]);

        $json = $this->confirmer([
            $this->ligne([
                'code_facultatif' => '101', 'nom' => 'Matoto', 'ville_obligatoire' => 'Kindia',
                'telephone_obligatoire' => '+224622671016',
                'description_facultatif' => '', 'longitude_facultatif' => '', 'latitude_facultatif' => '',
            ]),
        ]);

        $this->assertTrue($json['execute']);
        $existant->refresh();
        $this->assertSame('Kindia', $existant->ville);
        $this->assertSame('Site historique', $existant->description);
        $this->assertEqualsWithDelta(-13.6773, $existant->longitude, 0.0001);
        $this->assertEqualsWithDelta(9.5370, $existant->latitude, 0.0001);
    }

    public function test_analyser_flags_duplicate_code_in_file(): void
    {
        $json = $this->analyser([
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Matoto', 'telephone_obligatoire' => '+224622671016']),
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Madina', 'telephone_obligatoire' => '+224622671017']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $erreur = collect($json['lignes'])->firstWhere('statut', 'erreur');
        $this->assertNotNull($erreur);
        $this->assertStringContainsString('code_facultatif', $erreur['erreurs'][0]);
        $this->assertStringContainsString('101', $erreur['erreurs'][0]);
    }

    public function test_confirmer_rejects_file_with_duplicate_code_and_creates_nothing(): void
    {
        $json = $this->confirmer([
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Matoto', 'telephone_obligatoire' => '+224622671016']),
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Madina', 'telephone_obligatoire' => '+224622671017']),
        ]);

        $this->assertFalse($json['execute']);
        $this->assertDatabaseMissing('sites', ['organization_id' => $this->org->id, 'nom' => 'Matoto']);
        $this->assertDatabaseMissing('sites', ['organization_id' => $this->org->id, 'nom' => 'Madina']);
    }

    public function test_confirmer_without_code_keeps_legacy_create_or_skip_behavior(): void
    {
        $existant = Site::create([
            'organization_id' => $this->org->id, 'nom' => 'Matoto',
            'type' => 'depot', 'ville' => 'Kindia', 'quartier' => 'Centre',
        ]);

        $json = $this->confirmer([
            $this->ligne(['nom' => 'Matoto', 'type' => 'Siège', 'ville_obligatoire' => 'Conakry']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(0, $json['crees']);
        $this->assertSame(0, $json['mis_a_jour']);
        $this->assertSame(1, $json['existants_ignores']);

        $existant->refresh();
        $this->assertSame('depot', $existant->type->value);
        $this->assertSame('Kindia', $existant->ville);
    }

    public function test_confirmer_by_code_targeting_soft_deleted_site_is_blocked(): void
    {
        // Un code n'est jamais réattribué dans ce projet, même après
        // suppression (cf. Site::boot(), qui évite aussi ces codes lors de la
        // génération automatique). Un import qui vise le code d'un site
        // archivé ne doit donc ni le recréer silencieusement (violerait
        // l'unicité en base), ni le mettre à jour (ressusciterait
        // silencieusement un enregistrement supprimé) : la ligne est bloquée
        // en erreur, à traiter manuellement (ex: restaurer le site avant de
        // réimporter).
        $archive = Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Ancien Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
        ]);
        $archive->delete();

        $json = $this->confirmer([
            $this->ligne(['code_facultatif' => '101', 'nom' => 'Nouveau Matoto', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertFalse($json['execute']);
        $this->assertDatabaseMissing('sites', ['organization_id' => $this->org->id, 'nom' => 'Nouveau Matoto']);
        $this->assertSame(1, Site::withTrashed()->where('organization_id', $this->org->id)->where('code', '101')->count());
    }

    public function test_analyser_warns_when_new_code_row_shares_exact_name_with_different_existing_site(): void
    {
        Site::create([
            'organization_id' => $this->org->id, 'code' => '101', 'nom' => 'Matoto',
            'type' => 'siege', 'ville' => 'Conakry', 'quartier' => 'Matoto',
        ]);

        $json = $this->analyser([
            $this->ligne(['code_facultatif' => '102', 'nom' => 'Matoto', 'telephone_obligatoire' => '+224622671016']),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
        $this->assertSame('nouveau', $json['lignes'][0]['statut']);
        $this->assertNotEmpty($json['lignes'][0]['avertissements']);
    }
}
