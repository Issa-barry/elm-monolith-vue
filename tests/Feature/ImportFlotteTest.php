<?php

namespace Tests\Feature;

use App\Models\EquipeLivraison;
use App\Models\ImportFlotte;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportFlotteTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS_VEHICULES = [
        'vehicule_immatriculation', 'vehicule_nom', 'vehicule_type', 'vehicule_categorie',
        'vehicule_site', 'vehicule_pris_en_charge_par_usine',
        'proprietaire_nom', 'proprietaire_prenom', 'proprietaire_telephone', 'proprietaire_pays',
    ];

    private const HEADERS_LIVREURS = [
        'vehicule_immatriculation', 'livreur_nom', 'livreur_prenom', 'livreur_telephone', 'livreur_role',
    ];

    private Organization $org;

    private User $user;

    private Site $site;

    private TypeVehicule $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Tricycle']);
        $this->user = $this->makeUser(['imports-flotte.create', 'imports-flotte.read']);
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
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function ligneVehiculeExterne(array $overrides = []): array
    {
        return array_replace([
            'vehicule_immatriculation' => 'RC-1234-A',
            'vehicule_nom' => 'Camion 1',
            'vehicule_type' => 'Tricycle',
            'vehicule_categorie' => 'externe',
            'vehicule_site' => '',
            'vehicule_pris_en_charge_par_usine' => 'oui',
            'proprietaire_nom' => 'Diallo',
            'proprietaire_prenom' => 'Mamadou',
            'proprietaire_telephone' => '622000001',
            'proprietaire_pays' => 'GN',
        ], $overrides);
    }

    private function ligneLivreurChauffeur(array $overrides = []): array
    {
        return array_replace([
            'vehicule_immatriculation' => 'RC-1234-A',
            'livreur_nom' => 'Camara',
            'livreur_prenom' => 'Ibrahima',
            'livreur_telephone' => '623000001',
            'livreur_role' => 'chauffeur',
        ], $overrides);
    }

    private function uploadFile(array $lignesVehicules, array $lignesLivreurs): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $sheetVehicules = $spreadsheet->getActiveSheet();
        $sheetVehicules->setTitle('vehicules');
        $sheetVehicules->fromArray(self::HEADERS_VEHICULES, null, 'A1');
        foreach ($lignesVehicules as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', self::HEADERS_VEHICULES);
            $sheetVehicules->fromArray($row, null, 'A'.($i + 2));
        }

        $sheetLivreurs = $spreadsheet->createSheet();
        $sheetLivreurs->setTitle('livreurs');
        $sheetLivreurs->fromArray(self::HEADERS_LIVREURS, null, 'A1');
        foreach ($lignesLivreurs as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', self::HEADERS_LIVREURS);
            $sheetLivreurs->fromArray($row, null, 'A'.($i + 2));
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_flotte_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importer(array $lignesVehicules, array $lignesLivreurs): ImportFlotte
    {
        $this->actingAs($this->user)
            ->post(route('imports-flotte.store'), ['fichier' => $this->uploadFile($lignesVehicules, $lignesLivreurs)])
            ->assertRedirect();

        return ImportFlotte::firstOrFail();
    }

    /** Raccourci pour le cas le plus courant : un véhicule externe + un chauffeur. */
    private function importerVehiculeEtChauffeur(array $overridesVehicule = [], array $overridesLivreur = []): ImportFlotte
    {
        return $this->importer(
            [$this->ligneVehiculeExterne($overridesVehicule)],
            [$this->ligneLivreurChauffeur($overridesLivreur)]
        );
    }

    // ── accès / permissions ──────────────────────────────────────────────────

    public function test_create_redirects_unauthenticated_user(): void
    {
        $this->get(route('imports-flotte.create'))->assertRedirect(route('login'));
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        $this->actingAs($user)->get(route('imports-flotte.create'))->assertStatus(403);
    }

    // ── analyse ───────────────────────────────────────────────────────────────

    public function test_store_analyzes_file_and_reports_counts(): void
    {
        $import = $this->importerVehiculeEtChauffeur();

        $this->assertSame('analyse', $import->statut->value);
        $this->assertSame(1, $import->nb_groupes_valides);
        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertSame(2, $import->nb_lignes_total); // 1 ligne véhicule + 1 ligne livreur
    }

    public function test_analyse_flags_error_for_invalid_livreur_phone(): void
    {
        $import = $this->importerVehiculeEtChauffeur([], ['livreur_telephone' => '12345']);

        $this->assertSame(1, $import->nb_groupes_erreur);
    }

    public function test_analyse_flags_error_for_invalid_livreur_role(): void
    {
        $import = $this->importerVehiculeEtChauffeur([], ['livreur_role' => 'manager']);

        $this->assertSame(1, $import->nb_groupes_erreur);
    }

    public function test_analyse_flags_error_for_interne_vehicule_without_site(): void
    {
        $import = $this->importer(
            [$this->ligneVehiculeExterne([
                'vehicule_categorie' => 'interne',
                'vehicule_site' => '',
                'proprietaire_nom' => '', 'proprietaire_prenom' => '', 'proprietaire_telephone' => '', 'proprietaire_pays' => '',
            ])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
    }

    public function test_analyse_accepts_new_vehicule_without_any_livreur(): void
    {
        $import = $this->importer([$this->ligneVehiculeExterne()], []);

        $this->assertSame('analyse', $import->statut->value);
        $this->assertSame(1, $import->nb_groupes_valides);
        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_flags_error_when_livreur_references_unknown_vehicule(): void
    {
        $import = $this->importer([], [$this->ligneLivreurChauffeur()]);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Aucun véhicule', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    // ── confirmation / création ──────────────────────────────────────────────

    public function test_confirm_creates_proprietaire_vehicule_equipe_and_livreur_as_draft(): void
    {
        $import = $this->importerVehiculeEtChauffeur();

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $import->refresh();
        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1, $import->nb_proprietaires_crees);
        $this->assertSame(1, $import->nb_vehicules_crees);
        $this->assertSame(1, $import->nb_livreurs_crees);
        $this->assertSame(1, $import->nb_equipes_creees);

        $proprietaire = Proprietaire::where('organization_id', $this->org->id)->where('telephone', '+224622000001')->firstOrFail();
        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertSame($proprietaire->id, $vehicule->proprietaire_id);
        $this->assertSame('externe', $vehicule->categorie);
        $this->assertNotNull($import->termine_le);

        // Équipe créée à l'état "brouillon" : commission/montants à 0, à
        // finaliser dans Équipes de livraison (pas saisis dans le fichier).
        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->firstOrFail();
        $this->assertSame('0.00', $equipe->commission_unitaire_par_pack);
        $this->assertSame('0.00', $equipe->montant_par_pack_proprietaire);

        // Inactifs tant que la répartition n'est pas finalisée — même règle que
        // pour une création manuelle (VehiculeController::store()).
        $this->assertFalse($equipe->is_active);
        $this->assertFalse($vehicule->fresh()->is_active);

        $membre = $equipe->membres()->firstOrFail();
        $this->assertSame('chauffeur', $membre->role);
        $this->assertSame('0.00', $membre->montant_par_pack);

        $livreur = Livreur::where('organization_id', $this->org->id)->where('telephone', '+224623000001')->firstOrFail();
        $this->assertSame($livreur->id, $membre->livreur_id);
    }

    public function test_confirm_deactivates_already_active_vehicule_when_creating_draft_equipe(): void
    {
        // Véhicule déjà existant, actif, sans équipe (cas réel : créé manuellement
        // avant d'avoir une équipe assignée).
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'categorie' => 'externe',
            'type_vehicule_id' => $this->type->id,
            'is_active' => true,
        ]);

        $import = $this->importerVehiculeEtChauffeur();
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertFalse($vehicule->fresh()->is_active);
    }

    public function test_confirm_creates_vehicule_and_draft_equipe_without_any_livreur(): void
    {
        $import = $this->importer([$this->ligneVehiculeExterne()], []);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $import->refresh();
        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1, $import->nb_proprietaires_crees);
        $this->assertSame(1, $import->nb_vehicules_crees);
        $this->assertSame(0, $import->nb_livreurs_crees);
        $this->assertSame(1, $import->nb_equipes_creees);

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->firstOrFail();
        $this->assertSame(0, $equipe->membres()->count());
        $this->assertFalse($equipe->is_active);
        $this->assertFalse($vehicule->fresh()->is_active);
    }

    public function test_analyse_accepts_existing_vehicule_without_any_livreur_and_keeps_its_members(): void
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'categorie' => 'externe',
            'type_vehicule_id' => $this->type->id,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'commission_unitaire_par_pack' => 5000,
        ]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224623000001']);
        $equipe->membres()->create(['livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'montant_par_pack' => 3000]);

        $import = $this->importer([$this->ligneVehiculeExterne()], []);
        $this->assertSame(0, $import->nb_groupes_erreur);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(0, $import->nb_vehicules_crees);
        $this->assertSame(0, $import->nb_equipes_creees);
        $this->assertSame(1, $equipe->membres()->count());
        $this->assertSame('5000.00', $equipe->fresh()->commission_unitaire_par_pack);
    }

    public function test_confirm_with_two_livreurs_creates_both_members(): void
    {
        $import = $this->importer(
            [$this->ligneVehiculeExterne()],
            [
                $this->ligneLivreurChauffeur(),
                $this->ligneLivreurChauffeur([
                    'livreur_nom' => 'Soumah', 'livreur_prenom' => 'Fatoumata',
                    'livreur_telephone' => '623000002', 'livreur_role' => 'convoyeur',
                ]),
            ]
        );

        $this->assertSame(1, $import->nb_groupes_valides, 'les deux lignes livreurs doivent former un seul groupe (même immatriculation)');

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1, $import->nb_vehicules_crees);
        $this->assertSame(2, $import->nb_livreurs_crees);
        $this->assertSame(1, $import->nb_equipes_creees);

        $equipe = EquipeLivraison::firstOrFail();
        $this->assertSame(2, $equipe->membres()->count());
    }

    public function test_confirm_reuses_existing_proprietaire_by_phone(): void
    {
        $existant = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);

        $import = $this->importerVehiculeEtChauffeur();
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame(0, $import->nb_proprietaires_crees);
        $this->assertSame(1, Proprietaire::where('organization_id', $this->org->id)->count());

        $vehicule = Vehicule::firstOrFail();
        $this->assertSame($existant->id, $vehicule->proprietaire_id);
    }

    public function test_confirm_attaches_missing_livreur_to_existing_equipe_without_touching_its_commission(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622000001']);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'categorie' => 'externe',
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule_id' => $this->type->id,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $proprietaire->id,
            'commission_unitaire_par_pack' => 5000,
            'montant_par_pack_proprietaire' => 1000,
        ]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224623000001']);
        $equipe->membres()->create([
            'livreur_id' => $chauffeur->id,
            'role' => 'chauffeur',
            'montant_par_pack' => 3000,
        ]);

        // Le véhicule (déjà existant) doit quand même figurer dans la feuille
        // "vehicules" pour servir d'ancrage — seul le convoyeur est nouveau.
        $import = $this->importer(
            [$this->ligneVehiculeExterne()],
            [$this->ligneLivreurChauffeur([
                'livreur_nom' => 'Soumah', 'livreur_prenom' => 'Fatoumata',
                'livreur_telephone' => '623000002', 'livreur_role' => 'convoyeur',
            ])]
        );

        $this->assertSame(1, $import->nb_groupes_valides);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(0, $import->nb_vehicules_crees);
        $this->assertSame(0, $import->nb_equipes_creees);
        $this->assertSame(1, $import->nb_livreurs_crees);
        $this->assertSame(2, $equipe->membres()->count());

        // L'équipe déjà configurée n'est pas remise à zéro par l'import.
        $this->assertSame('5000.00', $equipe->fresh()->commission_unitaire_par_pack);
    }

    // ── confirmation idempotente / relance ───────────────────────────────────

    public function test_confirming_twice_the_second_call_is_rejected(): void
    {
        $import = $this->importerVehiculeEtChauffeur();

        // Driver "sync" en tests : le job s'exécute immédiatement dans le premier
        // appel, donc le deuxième appel trouve déjà le statut "termine" — l'update
        // conditionnel ne peut affecter aucune ligne dans les deux cas de figure
        // (double-clic avant traitement, ou nouvelle confirmation après coup).
        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect();

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $import->refresh();
        $this->assertSame(1, $import->nb_vehicules_crees, 'un seul véhicule créé malgré les deux confirmations');
    }

    public function test_retry_relaunches_a_failed_import(): void
    {
        $import = $this->importerVehiculeEtChauffeur();
        $import->update(['statut' => 'echoue']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.retry', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $import->refresh();
        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1, $import->nb_vehicules_crees);
    }

    public function test_retry_returns_422_when_import_is_not_failed(): void
    {
        $import = $this->importerVehiculeEtChauffeur();

        $this->actingAs($this->user)
            ->post(route('imports-flotte.retry', $import))
            ->assertStatus(422);
    }

    // ── tout ou rien ──────────────────────────────────────────────────────────

    public function test_confirm_returns_422_when_groups_have_errors(): void
    {
        $import = $this->importerVehiculeEtChauffeur([], ['livreur_telephone' => '12345']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(0, Proprietaire::count());
        $this->assertSame(0, Vehicule::count());
    }

    public function test_confirm_creates_nothing_when_one_group_among_several_is_invalid(): void
    {
        $import = $this->importer(
            [
                $this->ligneVehiculeExterne(),
                $this->ligneVehiculeExterne(['vehicule_immatriculation' => 'RC-9999-Z', 'proprietaire_telephone' => '622000099']),
            ],
            [
                $this->ligneLivreurChauffeur(),
                $this->ligneLivreurChauffeur([
                    'vehicule_immatriculation' => 'RC-9999-Z',
                    'livreur_telephone' => '12345', // invalide
                ]),
            ]
        );

        $this->assertSame(1, $import->nb_groupes_valides);
        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertFalse($import->estPret());

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        // Même le groupe valide n'est pas créé : tout ou rien.
        $this->assertSame(0, Vehicule::where('organization_id', $this->org->id)->count());
    }

    // ── organisation ──────────────────────────────────────────────────────────

    public function test_dedup_is_scoped_to_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        Proprietaire::factory()->create(['organization_id' => $autreOrg->id, 'telephone' => '+224622000001']);

        $import = $this->importerVehiculeEtChauffeur();
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        // Le propriétaire d'une autre organisation ne doit pas être réutilisé.
        $this->assertSame(1, $import->nb_proprietaires_crees);
    }

    public function test_show_returns_403_for_import_of_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $import = ImportFlotte::create([
            'organization_id' => $autreOrg->id,
            'user_id' => $this->user->id,
            'fichier_original' => 'test.xlsx',
            'fichier_path' => 'imports-flotte/x/test.xlsx',
            'statut' => 'analyse',
        ]);

        $this->actingAs($this->user)
            ->get(route('imports-flotte.show', $import))
            ->assertStatus(403);
    }
}
