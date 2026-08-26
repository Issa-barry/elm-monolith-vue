<?php

namespace Tests\Feature;

use App\Models\DepenseType;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class DepenseTypeImportTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const HEADERS = [
        'libelle', 'concerne', 'description_facultatif', 'commentaire_obligatoire', 'justificatif_obligatoire', 'statut',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['parametres.read', 'parametres.update']);
    }

    private function ligne(array $overrides = []): array
    {
        return array_replace([
            'libelle' => 'Frais de mission',
            'concerne' => 'Interne',
            'description_facultatif' => '',
            'commentaire_obligatoire' => '',
            'justificatif_obligatoire' => '',
            'statut' => '',
        ], $overrides);
    }

    private function uploadFile(array $lignes): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('types-depense');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        foreach ($lignes as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', self::HEADERS);
            foreach ($row as $col => $valeur) {
                $cellule = Coordinate::stringFromColumnIndex($col + 1).($i + 2);
                $sheet->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_depense_types_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function analyser(array $lignes): array
    {
        return $this->actingAs($this->user)
            ->post(route('depenses.types.import.analyser'), ['fichier' => $this->uploadFile($lignes)], ['Accept' => 'application/json'])
            ->json();
    }

    private function confirmer(array $lignes): array
    {
        return $this->actingAs($this->user)
            ->post(route('depenses.types.import.confirmer'), ['fichier' => $this->uploadFile($lignes)], ['Accept' => 'application/json'])
            ->json();
    }

    // ── permissions / accès ──────────────────────────────────────────────────

    public function test_modele_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('depenses.types.import.modele'))
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_modele_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('depenses.types.import.modele'))
            ->assertStatus(403);
    }

    public function test_analyser_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->post(route('depenses.types.import.analyser'), ['fichier' => $this->uploadFile([$this->ligne()])], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_confirmer_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->post(route('depenses.types.import.confirmer'), ['fichier' => $this->uploadFile([$this->ligne()])], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_analyser_redirects_unauthenticated_user(): void
    {
        $this->post(route('depenses.types.import.analyser'), ['fichier' => $this->uploadFile([$this->ligne()])])
            ->assertRedirect(route('login'));
    }

    // ── analyse ──────────────────────────────────────────────────────────────

    public function test_analyser_valid_file_reports_counts(): void
    {
        $json = $this->analyser([
            $this->ligne(['libelle' => 'Frais de mission']),
        ]);

        $this->assertSame(1, $json['nb_lignes_total']);
        $this->assertSame(1, $json['nb_nouveaux']);
        $this->assertSame(0, $json['nb_erreurs']);
    }

    public function test_analyser_flags_missing_libelle(): void
    {
        $json = $this->analyser([
            $this->ligne(['libelle' => '']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertStringContainsString('libelle', $json['lignes'][0]['erreurs'][0]);
    }

    public function test_analyser_flags_missing_concerne(): void
    {
        $json = $this->analyser([
            $this->ligne(['concerne' => '']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertStringContainsString('concerne', $json['lignes'][0]['erreurs'][0]);
    }

    public function test_analyser_flags_invalid_concerne_with_suggestion(): void
    {
        $json = $this->analyser([
            $this->ligne(['concerne' => 'Interme']), // faute de frappe sur "Interne"
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertStringContainsString('Interne', $json['lignes'][0]['erreurs'][0]);
    }

    public function test_analyser_flags_duplicate_libelle_within_file(): void
    {
        $json = $this->analyser([
            $this->ligne(['libelle' => 'Frais de mission']),
            $this->ligne(['libelle' => 'Frais de mission']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertSame('erreur', $json['lignes'][1]['statut']);
        $this->assertStringContainsString('doublon', $json['lignes'][1]['erreurs'][0]);
    }

    /**
     * Doublon refusé sans écrasement : un libellé qui correspond à un type déjà
     * présent (actif ou archivé) dans l'organisation est bloqué en erreur —
     * jamais transformé en mise à jour silencieuse (cf. brief).
     */
    public function test_analyser_flags_duplicate_of_existing_type(): void
    {
        DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Restauration',
            'code' => 'restauration',
        ]);

        $json = $this->analyser([
            $this->ligne(['libelle' => 'Restauration']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
        $this->assertStringContainsString('existe déjà', $json['lignes'][0]['erreurs'][0]);
    }

    public function test_analyser_flags_duplicate_of_soft_deleted_type(): void
    {
        $type = DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Restauration',
            'code' => 'restauration',
        ]);
        $type->delete();

        $json = $this->analyser([
            $this->ligne(['libelle' => 'Restauration']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
    }

    public function test_analyser_does_not_flag_same_libelle_in_other_org(): void
    {
        DepenseType::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'libelle' => 'Restauration',
            'code' => 'restauration',
        ]);

        $json = $this->analyser([
            $this->ligne(['libelle' => 'Restauration']),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
    }

    public function test_analyser_parses_oui_non_columns(): void
    {
        $json = $this->analyser([
            $this->ligne([
                'libelle' => 'Frais de mission',
                'commentaire_obligatoire' => 'Oui',
                'justificatif_obligatoire' => 'Non',
                'statut' => 'Inactif',
            ]),
        ]);

        $this->assertSame(0, $json['nb_erreurs']);
    }

    public function test_analyser_flags_invalid_oui_non_value(): void
    {
        $json = $this->analyser([
            $this->ligne(['commentaire_obligatoire' => 'peut-être']),
        ]);

        $this->assertSame(1, $json['nb_erreurs']);
    }

    // ── confirmation ─────────────────────────────────────────────────────────

    public function test_confirmer_creates_valid_rows(): void
    {
        $json = $this->confirmer([
            $this->ligne(['libelle' => 'Frais de mission', 'concerne' => 'Interne']),
            $this->ligne(['libelle' => 'Prime exceptionnelle', 'concerne' => 'Salarié', 'commentaire_obligatoire' => 'Oui']),
        ]);

        $this->assertTrue($json['execute']);
        $this->assertSame(2, $json['crees']);

        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'libelle' => 'Frais de mission',
            'categorie' => 'interne',
        ]);
        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'libelle' => 'Prime exceptionnelle',
            'categorie' => 'employe',
            'commentaire_obligatoire' => true,
        ]);
    }

    /**
     * Import atomique : une seule ligne en erreur bloque tout le fichier, y
     * compris les lignes par ailleurs valides — aucune création partielle.
     */
    public function test_confirmer_is_atomic_creates_nothing_on_any_error(): void
    {
        $json = $this->confirmer([
            $this->ligne(['libelle' => 'Frais de mission', 'concerne' => 'Interne']),
            $this->ligne(['libelle' => '', 'concerne' => 'Interne']), // en erreur
        ]);

        $this->assertFalse($json['execute'] ?? false);
        $this->assertDatabaseMissing('depense_types', [
            'organization_id' => $this->org->id,
            'libelle' => 'Frais de mission',
        ]);
        $this->assertSame(0, DepenseType::where('organization_id', $this->org->id)->count());
    }

    public function test_confirmer_does_not_overwrite_existing_type_on_duplicate(): void
    {
        $existing = DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Restauration',
            'code' => 'restauration',
            'description' => 'Description originale',
        ]);

        $json = $this->confirmer([
            $this->ligne(['libelle' => 'Restauration', 'description_facultatif' => 'Nouvelle description tentée']),
        ]);

        $this->assertFalse($json['execute'] ?? false);
        $this->assertSame(1, $json['nb_erreurs']);

        $existing->refresh();
        $this->assertSame('Description originale', $existing->description);
        $this->assertSame(1, DepenseType::where('organization_id', $this->org->id)->count());
    }

    public function test_confirmer_reanalyses_file_ignoring_stale_client_preview(): void
    {
        // Un type est créé entre l'aperçu et la confirmation — la confirmation doit
        // re-détecter le doublon plutôt que de faire confiance à un aperçu périmé.
        $lignes = [$this->ligne(['libelle' => 'Restauration'])];
        $apercu = $this->analyser($lignes);
        $this->assertSame(0, $apercu['nb_erreurs']);

        DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Restauration',
            'code' => 'restauration',
        ]);

        $json = $this->confirmer($lignes);

        $this->assertFalse($json['execute'] ?? false);
        $this->assertSame(1, $json['nb_erreurs']);
    }
}
