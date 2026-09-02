<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\EquipeLivraison;
use App\Models\ImportFlotte;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

class ImportFlotteTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    /**
     * Ne contient volontairement plus de colonne de capacité fixe (ancienne convention
     * vehicule_capacite_sachets/vehicule_capacite_bouteilles, abandonnée) : les colonnes
     * "capacite__<REFERENCE>" sont désormais dynamiques, ajoutées automatiquement par
     * uploadFile() à partir des clés présentes dans les lignes fournies par chaque test.
     */
    private const HEADERS_VEHICULES = [
        'vehicule_immatriculation', 'vehicule_nom', 'vehicule_type', 'vehicule_categorie',
        'vehicule_site', 'vehicule_livraison_vente', 'vehicule_livraison_logistique',
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

        // Ce fichier ne teste pas la disponibilité du stock — évite que le nouveau contrôle de
        // CommandeVenteController::store() (23/08/2026, cf. CommandeVenteService::
        // siteAutoriseNouvelleCommande()) ne bloque des commandes de test sans rapport avec le stock.
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Tricycle']);
        $this->user = $this->makeUser(['imports-flotte.create', 'imports-flotte.read']);
    }

    /**
     * Crée le propriétaire interne par défaut de $this->org et le rattache via
     * Organization::proprietaire_interne_id (cf. InstallationService::install() en conditions
     * réelles) — seule façon dont Proprietaire::interneParDefautId() peut désormais le
     * retrouver, plus aucune magie sur le numéro de téléphone.
     */
    private function defaultInterneProprietaire(array $attributes = []): Proprietaire
    {
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            ...$attributes,
        ]);
        $this->org->forceFill(['proprietaire_interne_id' => $proprietaire->id])->save();

        return $proprietaire;
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

    /** Véhicule vente + propriétaire tiers renseigné — le cas le plus courant du fichier. */
    private function ligneVehicule(array $overrides = []): array
    {
        // vehicule_site est obligatoire quel que soit le propriétaire.
        return array_replace([
            'vehicule_immatriculation' => 'RC-1234-A',
            'vehicule_nom' => 'Camion 1',
            'vehicule_type' => 'Tricycle',
            'vehicule_categorie' => 'partenaire',
            'vehicule_site' => 'Matoto',
            'vehicule_livraison_vente' => 'oui',
            'vehicule_livraison_logistique' => 'non',
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

    /**
     * En-têtes de la feuille "vehicules" pour CET upload : les colonnes fixes
     * (HEADERS_VEHICULES) plus toute clé additionnelle présente dans une des lignes fournies
     * (typiquement "capacite__<REFERENCE>") — permet à chaque test de déclarer ses propres
     * colonnes de capacité sans toucher au gabarit partagé.
     *
     * @return string[]
     */
    private function entetesVehicules(array $lignesVehicules): array
    {
        $entetes = self::HEADERS_VEHICULES;
        foreach ($lignesVehicules as $ligne) {
            foreach (array_keys($ligne) as $cle) {
                if (! in_array($cle, $entetes, true)) {
                    $entetes[] = $cle;
                }
            }
        }

        return $entetes;
    }

    private function uploadFile(array $lignesVehicules, array $lignesLivreurs): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $entetesVehicules = $this->entetesVehicules($lignesVehicules);

        $sheetVehicules = $spreadsheet->getActiveSheet();
        $sheetVehicules->setTitle('vehicules');
        $sheetVehicules->fromArray($entetesVehicules, null, 'A1');
        foreach ($lignesVehicules as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', $entetesVehicules);
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

    /**
     * Variante de uploadFile() prenant les en-têtes "vehicules" tels quels (pas de déduplication
     * automatique) — nécessaire pour reproduire un VRAI doublon de colonne dans le fichier (ex:
     * "capacite__SACHET_EAU" présente deux fois), qu'entetesVehicules() ne peut pas produire
     * puisqu'elle en fait justement l'union.
     *
     * @param  string[]  $entetesVehicules
     * @param  array<int, array<int, string>>  $lignesVehicules  une ligne = un tableau de valeurs, dans le même ordre que $entetesVehicules
     */
    private function uploadFileAvecEntetesBrutes(array $entetesVehicules, array $lignesVehicules): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $sheetVehicules = $spreadsheet->getActiveSheet();
        $sheetVehicules->setTitle('vehicules');
        $sheetVehicules->fromArray($entetesVehicules, null, 'A1');
        foreach ($lignesVehicules as $i => $row) {
            $sheetVehicules->fromArray($row, null, 'A'.($i + 2));
        }

        $sheetLivreurs = $spreadsheet->createSheet();
        $sheetLivreurs->setTitle('livreurs');
        $sheetLivreurs->fromArray(self::HEADERS_LIVREURS, null, 'A1');

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

    /** Raccourci pour le cas le plus courant : un véhicule vente + un chauffeur. */
    private function importerVehiculeEtChauffeur(array $overridesVehicule = [], array $overridesLivreur = []): ImportFlotte
    {
        return $this->importer(
            [$this->ligneVehicule($overridesVehicule)],
            [$this->ligneLivreurChauffeur($overridesLivreur)]
        );
    }

    // ── mode "livreurs seulement" (TypeImportFlotte::LIVREURS) ─────────────────

    private function uploadLivreursOnlyFile(array $lignesLivreurs): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('livreurs');
        $sheet->fromArray(self::HEADERS_LIVREURS, null, 'A1');
        foreach ($lignesLivreurs as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', self::HEADERS_LIVREURS);
            $sheet->fromArray($row, null, 'A'.($i + 2));
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_flotte_livreurs_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile($tmpPath, 'import-livreurs.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importerLivreursSeul(array $lignesLivreurs): ImportFlotte
    {
        $this->actingAs($this->user)
            ->post(route('imports-flotte.store'), [
                'type' => 'livreurs',
                'fichier' => $this->uploadLivreursOnlyFile($lignesLivreurs),
            ])
            ->assertRedirect();

        return ImportFlotte::firstOrFail();
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

    public function test_analyse_flags_error_for_vehicule_without_site(): void
    {
        $import = $this->importer(
            [$this->ligneVehicule([
                'vehicule_site' => '',
                'vehicule_categorie' => 'interne',
                'proprietaire_nom' => '', 'proprietaire_prenom' => '', 'proprietaire_telephone' => '', 'proprietaire_pays' => '',
            ])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
    }

    // ── usage vente/logistique : vide/absent = "non", jamais un usage par défaut ───────

    public function test_analyse_accepts_vehicule_sans_aucun_usage(): void
    {
        // Un véhicule sans aucun usage n'est plus une erreur d'analyse (cf.
        // ImportFlotteParser) : il est simplement importé sans usage, non exploitable
        // tant qu'un usage n'est pas défini — voir test_confirm_creates_vehicule_sans_usage_quand_colonnes_a_non.
        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_livraison_vente' => 'non', 'vehicule_livraison_logistique' => 'non'])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertSame(1, $import->nb_groupes_valides);
    }

    public function test_confirm_creates_vehicule_sans_usage_quand_colonnes_a_non(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['vehicule_livraison_vente' => 'non', 'vehicule_livraison_logistique' => 'non']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertFalse($vehicule->livraison_vente);
        $this->assertFalse($vehicule->livraison_logistique);
        $this->assertFalse($vehicule->aAuMoinsUnUsage());
        $this->assertSame('Usage non défini', $vehicule->usage_label);
    }

    public function test_confirm_creates_vehicule_sans_usage_quand_colonnes_vides(): void
    {
        // Cellules vides (jamais renseignées) : même comportement qu'un "non" explicite —
        // jamais un repli sur un usage vente par défaut, contrairement à l'ancien comportement.
        $import = $this->importerVehiculeEtChauffeur(['vehicule_livraison_vente' => '', 'vehicule_livraison_logistique' => '']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertFalse($vehicule->livraison_vente);
        $this->assertFalse($vehicule->livraison_logistique);
    }

    public function test_confirm_creates_vehicule_logistique_uniquement(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['vehicule_livraison_vente' => 'non', 'vehicule_livraison_logistique' => 'oui']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertFalse($vehicule->livraison_vente);
        $this->assertTrue($vehicule->livraison_logistique);
    }

    public function test_confirm_creates_vehicule_vente_et_logistique(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['vehicule_livraison_vente' => 'oui', 'vehicule_livraison_logistique' => 'oui']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertTrue($vehicule->livraison_vente);
        $this->assertTrue($vehicule->livraison_logistique);
    }

    public function test_analyse_accepts_yes_no_spellings_for_usage(): void
    {
        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_livraison_vente' => 'yes', 'vehicule_livraison_logistique' => 'no'])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertTrue($import->rapport['groupes'][0]['vehicule']['livraison_vente']);
        $this->assertFalse($import->rapport['groupes'][0]['vehicule']['livraison_logistique']);
    }

    public function test_analyse_flags_error_for_unrecognized_usage_value(): void
    {
        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_livraison_vente' => 'peut-etre'])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Usage vente invalide', $import->rapport['groupes'][0]['erreurs'][0]);
        $this->assertStringContainsString('peut-etre', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    public function test_analyse_flags_error_for_unrecognized_usage_logistique_value(): void
    {
        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_livraison_logistique' => 'X-invalide'])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Usage logistique invalide', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    /**
     * Un véhicule déjà en base (immatriculation déjà connue) n'est jamais réécrit sur ses usages
     * par l'import (cf. ImportFlotteExecutor::executerGroupe(), qui ne fait que réutiliser son id
     * — seuls la capacité et le site font exception, voir tests dédiés) — une colonne d'usage
     * vide sur une ligne "vehicules" qui sert d'ancrage à des livreurs supplémentaires ne doit
     * donc jamais désactiver un usage déjà configuré.
     */
    public function test_confirm_ne_modifie_pas_les_usages_dun_vehicule_deja_existant(): void
    {
        $vehiculeExistant = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
            'site_id' => $this->site->id,
            'livraison_vente' => true,
            'livraison_logistique' => true,
        ]);

        $import = $this->importerVehiculeEtChauffeur(['vehicule_livraison_vente' => '', 'vehicule_livraison_logistique' => '']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehiculeExistant->refresh();
        $this->assertTrue($vehiculeExistant->livraison_vente);
        $this->assertTrue($vehiculeExistant->livraison_logistique);
    }

    /**
     * Contrairement au reste d'une ligne "véhicule déjà existant" (simple ancrage, jamais
     * modifié — cf. test ci-dessus), le site EST mis à jour — un véhicule peut changer de site
     * d'affectation entre deux imports, et une ré-importation doit pouvoir le refléter (cf.
     * ImportFlotteExecutor::executerGroupe()).
     */
    public function test_confirm_met_a_jour_le_site_dun_vehicule_deja_existant(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $vehiculeExistant = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
            'site_id' => $autreSite->id,
        ]);

        // ligneVehicule() envoie 'vehicule_site' => 'Matoto' ($this->site), différent du site
        // actuel du véhicule (Kaloum).
        $import = $this->importerVehiculeEtChauffeur();

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $this->assertSame($this->site->id, $vehiculeExistant->fresh()->site_id);
    }

    /** L'aperçu (avant confirmation) reste cohérent avec ce qui sera réellement appliqué. */
    public function test_analyse_previews_existing_vehicule_usage_sur_colonnes_vides(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
            'site_id' => $this->site->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
        ]);

        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_livraison_vente' => '', 'vehicule_livraison_logistique' => ''])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertFalse($import->rapport['groupes'][0]['vehicule']['livraison_vente']);
        $this->assertTrue($import->rapport['groupes'][0]['vehicule']['livraison_logistique']);
    }

    public function test_analyse_accepts_new_vehicule_without_any_livreur(): void
    {
        $import = $this->importer([$this->ligneVehicule()], []);

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

    public function test_analyse_flags_error_for_duplicate_immatriculation_in_vehicules_sheet(): void
    {
        // Cas réel : la même immatriculation apparaît deux fois dans la
        // feuille "vehicules" (erreur de saisie). Sans ce contrôle, les deux
        // lignes passeraient l'analyse puis feraient planter la confirmation
        // sur la contrainte d'unicité en base.
        $import = $this->importer(
            [
                $this->ligneVehicule(['proprietaire_telephone' => '622000001']),
                $this->ligneVehicule(['proprietaire_telephone' => '622000002']),
            ],
            []
        );

        $this->assertSame(0, $import->nb_groupes_valides);
        $this->assertSame(2, $import->nb_groupes_erreur);
        $this->assertStringContainsString('RC-1234-A', $import->rapport['groupes'][0]['erreurs'][0]);
        $this->assertStringContainsString('une seule ligne par véhicule', $import->rapport['groupes'][0]['erreurs'][0]);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(0, Vehicule::where('organization_id', $this->org->id)->count());
    }

    /**
     * Même plaque déjà en base, écrite différemment dans le fichier (espaces/points/tirets/casse)
     * : doit retrouver le véhicule existant, jamais en créer un doublon écrit différemment.
     */
    public function test_analyse_retrouve_vehicule_existant_malgre_un_format_dimmatriculation_different(): void
    {
        $existant = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'BK-4627-02',
        ]);

        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_immatriculation' => 'bk 4627.02'])],
            [$this->ligneLivreurChauffeur(['vehicule_immatriculation' => 'bk 4627.02'])]
        );

        $this->assertSame(0, $import->nb_groupes_erreur);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $this->assertSame(1, Vehicule::where('organization_id', $this->org->id)->count());
        $this->assertSame('BK-4627-02', $existant->fresh()->immatriculation);
    }

    /**
     * Immatriculation proche d'un véhicule déjà en base, mais pas identique même après
     * normalisation (ex : suffixe manquant) — jamais fusionné automatiquement, mais signalé
     * pour que l'utilisateur confirme/corrige au lieu de créer silencieusement un doublon.
     */
    public function test_analyse_signale_une_immatriculation_proche_dun_vehicule_existant(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'nom_vehicule' => 'Cousin',
            'immatriculation' => 'BK-4627-02',
        ]);

        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_immatriculation' => 'BK4627'])],
            [$this->ligneLivreurChauffeur(['vehicule_immatriculation' => 'BK4627'])]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
        $erreur = $import->rapport['groupes'][0]['erreurs'][0];
        $this->assertStringContainsString("Immatriculation proche d'un véhicule existant", $erreur);
        $this->assertStringContainsString('Cousin', $erreur);
        $this->assertStringContainsString('BK-4627-02', $erreur);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(1, Vehicule::where('organization_id', $this->org->id)->count());
    }

    public function test_analyse_flags_error_for_livreur_attached_to_two_different_vehicules(): void
    {
        // Un livreur pas encore en base, rattaché à deux véhicules différents
        // dans le même fichier : sans contrôle, les deux lignes passeraient
        // l'analyse puis feraient planter la confirmation sur la contrainte
        // d'unicité (telephone, organization_id) de la table livreurs.
        $import = $this->importer(
            [
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-1111-A', 'proprietaire_telephone' => '622000001']),
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-2222-B', 'proprietaire_telephone' => '622000002']),
            ],
            [
                $this->ligneLivreurChauffeur(['vehicule_immatriculation' => 'RC-1111-A']),
                $this->ligneLivreurChauffeur(['vehicule_immatriculation' => 'RC-2222-B']),
            ]
        );

        // Un seul groupe d'erreur pour le conflit (pas un par véhicule concerné —
        // cf. groupesConflitLivreurMultiVehicules) : les deux véhicules restent
        // par ailleurs des groupes valides (simplement sans le livreur litigieux,
        // ni équipe puisqu'aucun autre livreur ne leur est rattaché).
        $this->assertSame(2, $import->nb_groupes_valides);
        $this->assertSame(1, $import->nb_groupes_erreur);

        $groupeErreur = collect($import->rapport['groupes'])->firstWhere('statut', 'erreur');
        $erreur = $groupeErreur['erreurs'][0];
        $this->assertStringContainsString('+224623000001', $erreur);
        $this->assertStringContainsString('plusieurs véhicules différents', $erreur);
        $this->assertStringContainsString('RC-1111-A', $erreur);
        $this->assertStringContainsString('RC-2222-B', $erreur);
        $this->assertStringContainsString('RC-1111-A, RC-2222-B', $groupeErreur['immatriculation']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(0, Livreur::where('organization_id', $this->org->id)->count());
    }

    /**
     * Même nom qu'un livreur déjà en base (casse/espaces différents), mais un tout autre
     * numéro de téléphone — le rapprochement reste strictement par téléphone (jamais de fusion
     * automatique par nom), mais l'utilisateur doit être bloqué avant de créer un second
     * `Livreur`/`Personne` désignant probablement la même personne.
     */
    public function test_analyse_bloque_un_livreur_dont_le_nom_correspond_exactement_a_un_livreur_existant(): void
    {
        Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'Camara',
            'prenom' => 'Ibrahima',
            'telephone' => '+224623000099',
        ]);

        $import = $this->importerVehiculeEtChauffeur([], [
            'livreur_nom' => 'camara',
            'livreur_prenom' => '  Ibrahima ',
            'livreur_telephone' => '623000001',
        ]);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $erreur = $import->rapport['groupes'][0]['erreurs'][0];
        $this->assertStringContainsString('existe déjà', $erreur);
        $this->assertStringContainsString('+224623000099', $erreur);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(1, Livreur::where('organization_id', $this->org->id)->count());
    }

    /**
     * Nom proche (faute de frappe) d'un livreur déjà en base, téléphone différent — signalé
     * pour confirmation/correction, jamais fusionné ni créé silencieusement (même mécanique que
     * l'immatriculation proche d'un véhicule existant, cf. test ci-dessus).
     */
    public function test_analyse_signale_un_nom_de_livreur_proche_dun_livreur_existant(): void
    {
        Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'Camara',
            'prenom' => 'Ibrahima',
            'telephone' => '+224623000099',
        ]);

        $import = $this->importerVehiculeEtChauffeur([], [
            'livreur_nom' => 'Camaraa',
            'livreur_prenom' => 'Ibrahima',
            'livreur_telephone' => '623000001',
        ]);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $erreur = $import->rapport['groupes'][0]['erreurs'][0];
        $this->assertStringContainsString("nom proche d'un livreur existant", $erreur);
        $this->assertStringContainsString('+224623000099', $erreur);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(1, Livreur::where('organization_id', $this->org->id)->count());
    }

    /**
     * Le même livreur déjà en base, réimporté avec exactement le même téléphone : le
     * rapprochement se fait par téléphone (chemin normal, jamais par le contrôle de nom
     * ci-dessus) — aucune erreur, pas de doublon.
     */
    public function test_analyse_naffiche_aucune_erreur_quand_le_meme_livreur_est_retrouve_par_telephone(): void
    {
        Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'Camara',
            'prenom' => 'Ibrahima',
            'telephone' => '+224623000001',
        ]);

        $import = $this->importerVehiculeEtChauffeur([], [
            'livreur_nom' => 'Camara',
            'livreur_prenom' => 'Ibrahima',
            'livreur_telephone' => '623000001',
        ]);

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertSame(1, $import->nb_groupes_valides);
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

        $proprietaire = Proprietaire::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->firstOrFail();
        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertSame($proprietaire->id, $vehicule->proprietaire_id);
        $this->assertTrue($vehicule->livraison_vente);
        $this->assertFalse($vehicule->livraison_logistique);
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

        $livreur = Livreur::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+224623000001'))->firstOrFail();
        $this->assertSame($livreur->id, $membre->livreur_id);
    }

    /**
     * Colonnes dynamiques "capacite__<REFERENCE>" (ex: "capacite__SACHET_EAU") — une par
     * catégorie du catalogue produit à plafonner, en nombre libre (plus de convention à deux
     * colonnes fixes "sachets"/"bouteilles") — cf. ImportFlotteParser::resoudreColonnesCapacite() /
     * ImportFlotteExecutor::upsertCapacite(). Résolution par `reference`, jamais par `nom` : le
     * libellé choisi ici ("Sachet eau" / "Bouteille") est volontairement différent de la
     * convention "elm" ("Sachet d'eau" / "Bouteille d'eau", cf. ProduitsSeeder) pour prouver que
     * seule la référence compte.
     */
    public function test_confirm_creates_vehicule_avec_plusieurs_capacites_sur_differentes_categories(): void
    {
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);
        $bouteilles = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'reference' => 'BOUTEILLE_EAU']);

        $import = $this->importerVehiculeEtChauffeur([
            'capacite__SACHET_EAU' => '90',
            'capacite__BOUTEILLE_EAU' => '40',
        ]);

        // L'aperçu (avant confirmation) doit déjà exposer les deux capacités comme "à créer" —
        // c'est ce que consomme la colonne "Capacités" de l'écran d'analyse (ImportsFlotte/Show.vue).
        $capacitesApercu = collect($import->rapport['groupes'][0]['vehicule']['capacites'])->keyBy('reference');
        $this->assertSame('creer', $capacitesApercu['SACHET_EAU']['statut']);
        $this->assertSame(90, $capacitesApercu['SACHET_EAU']['valeur']);
        $this->assertNull($capacitesApercu['SACHET_EAU']['valeur_actuelle']);
        $this->assertSame('creer', $capacitesApercu['BOUTEILLE_EAU']['statut']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertNull($vehicule->capacite_packs, 'la colonne legacy ne doit plus être alimentée par l\'import');
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $sachets->id, 'capacite_max' => 90]);
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 40]);
    }

    /**
     * Preuve que la résolution se fait par `reference` et non par `nom` : la catégorie est
     * renommée APRÈS sa création (action normale, supportée par le CRUD Catégories) — l'import
     * doit continuer à la résoudre correctement, alors qu'un matching par nom échouerait ici.
     */
    public function test_confirm_resout_la_capacite_meme_apres_renommage_de_la_categorie(): void
    {
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);
        $sachets->update(['nom' => 'Sachets 25 unités']);
        $this->assertSame('SACHET_EAU', $sachets->fresh()->reference, 'la référence ne doit jamais être régénérée au renommage');

        $import = $this->importerVehiculeEtChauffeur(['capacite__SACHET_EAU' => '90']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $sachets->id, 'capacite_max' => 90]);
    }

    /**
     * La référence "SACHET_EAU" d'une organisation ne doit jamais résoudre la capacité d'une
     * AUTRE organisation, même si celle-ci n'a créé aucune catégorie du tout — isolation
     * multi-tenant. Une colonne "capacite__<REFERENCE>" est écrite par l'organisation
     * elle-même : une référence introuvable pour CETTE organisation bloque le groupe entier
     * (comme un type de véhicule ou un site introuvable), jamais un simple avertissement.
     */
    public function test_confirm_nutilise_jamais_une_categorie_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        Categorie::create(['organization_id' => $autreOrg->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);

        $import = $this->importerVehiculeEtChauffeur(['capacite__SACHET_EAU' => '90']);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Référence catégorie inconnue : SACHET_EAU', $import->rapport['groupes'][0]['erreurs'][0]);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(0, Vehicule::where('organization_id', $this->org->id)->count());
    }

    public function test_confirm_vehicule_sans_capacite_saisie_reste_null(): void
    {
        // Capacité facultative : le véhicule reste simplement non plafonné, pas d'écriture
        // forcée en base pour ce cas — cf. VehiculeCapaciteService.
        $import = $this->importerVehiculeEtChauffeur();

        $this->assertSame([], $import->rapport['groupes'][0]['vehicule']['capacites']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertNull($vehicule->capacite_packs);
        $this->assertNull($vehicule->capacite_bouteilles);
        $this->assertSame(0, $vehicule->capacites()->count());
    }

    /**
     * Organisation sans Categorie de `reference` "SACHET_EAU" (convention non suivie, ou pas
     * encore créée) : une colonne "capacite__<REFERENCE>" est écrite par l'organisation
     * elle-même — une référence introuvable est presque toujours une faute de frappe, donc
     * bloque le groupe entier (comme un type de véhicule ou un site introuvable), jamais un
     * simple avertissement qui laisserait silencieusement le véhicule non plafonné.
     */
    public function test_analyse_bloque_quand_categorie_capacite_absente(): void
    {
        $import = $this->importer(
            [$this->ligneVehicule(['capacite__SACHET_EAU' => '90'])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertSame(0, $import->nb_groupes_valides);
        $this->assertStringContainsString('Référence catégorie inconnue : SACHET_EAU', $import->rapport['groupes'][0]['erreurs'][0]);
        $this->assertSame(1, $import->rapport['groupes'][0]['nb_capacites_erreur']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertStatus(422);

        $this->assertSame(0, Vehicule::where('organization_id', $this->org->id)->count());
    }

    /**
     * Contrairement au reste d'une ligne "véhicule déjà existant" (simple ancrage, jamais
     * modifié), la capacité EST mise à jour — une ré-importation avec des valeurs corrigées doit
     * pouvoir corriger la flotte déjà configurée (cf. ImportFlotteExecutor::upsertCapacite()).
     * L'aperçu doit distinguer ce cas ('modifier', avec l'ancienne ET la nouvelle valeur) d'une
     * simple création — cf. formatCapacite() côté Vue (ImportsFlotte/Show.vue).
     */
    public function test_confirm_met_a_jour_la_capacite_dun_vehicule_deja_existant(): void
    {
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);
        $vehiculeExistant = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);
        $vehiculeExistant->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 50]);

        $import = $this->importerVehiculeEtChauffeur(['capacite__SACHET_EAU' => '120']);

        $capaciteApercu = $import->rapport['groupes'][0]['vehicule']['capacites'][0];
        $this->assertSame('modifier', $capaciteApercu['statut']);
        $this->assertSame(50, $capaciteApercu['valeur_actuelle']);
        $this->assertSame(120, $capaciteApercu['valeur']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehiculeExistant->id, 'categorie_id' => $sachets->id, 'capacite_max' => 120]);
        $this->assertSame(1, $vehiculeExistant->capacites()->count(), 'upsert, pas de doublon de ligne');
    }

    /**
     * Une capacité importée avec exactement la même valeur que celle déjà en base doit être
     * signalée 'inchangee' à l'aperçu — jamais présentée comme une modification, pour ne pas
     * inquiéter l'utilisateur sur un changement qui n'en est pas un.
     */
    public function test_analyse_marque_la_capacite_inchangee_quand_meme_valeur_en_base(): void
    {
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);
        $vehiculeExistant = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);
        $vehiculeExistant->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 90]);

        $import = $this->importerVehiculeEtChauffeur(['capacite__SACHET_EAU' => '90']);

        $capaciteApercu = $import->rapport['groupes'][0]['vehicule']['capacites'][0];
        $this->assertSame('inchangee', $capaciteApercu['statut']);
        $this->assertSame(90, $capaciteApercu['valeur_actuelle']);
        $this->assertSame(90, $capaciteApercu['valeur']);
    }

    /**
     * Une colonne de capacité laissée VIDE lors d'une ré-importation ne doit jamais effacer ou
     * remettre à zéro une capacité déjà configurée pour ce véhicule — "cellule vide" signifie
     * "rien à dire sur ce champ", jamais "supprimer la valeur existante" (cf.
     * ImportFlotteExecutor::upsertCapacite(), qui ignore silencieusement une valeur null).
     */
    public function test_confirm_cellule_vide_sur_reimport_ne_touche_pas_a_la_capacite_existante(): void
    {
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);
        $vehiculeExistant = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);
        $vehiculeExistant->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 50]);

        // Aucune colonne "capacite__SACHET_EAU" du tout sur cette ligne de ré-import —
        // ligneVehicule() ne fournit pas cette clé par défaut.
        $import = $this->importerVehiculeEtChauffeur();

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehiculeExistant->id, 'categorie_id' => $sachets->id, 'capacite_max' => 50]);
        $this->assertSame(1, $vehiculeExistant->capacites()->count());
    }

    /**
     * Doublon de colonne de capacité (deux fois la référence "SACHET_EAU", même normalisée en
     * casse) : la résolution serait ambiguë — array_combine() écraserait silencieusement l'une
     * des deux colonnes sans jamais lever d'erreur — donc bloqué globalement plutôt que de
     * deviner quelle colonne l'emporte, cf. ImportFlotteParser::resoudreColonnesCapacite().
     */
    public function test_analyse_rejette_un_doublon_de_colonne_capacite(): void
    {
        Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);

        $entetes = array_merge(self::HEADERS_VEHICULES, ['capacite__SACHET_EAU', 'capacite__sachet_eau']);
        $valeurs = [
            'vehicule_immatriculation' => 'RC-1234-A',
            'vehicule_nom' => 'Camion 1',
            'vehicule_type' => 'Tricycle',
            'vehicule_categorie' => 'partenaire',
            'vehicule_site' => 'Matoto',
            'vehicule_livraison_vente' => 'oui',
            'vehicule_livraison_logistique' => 'non',
            'proprietaire_nom' => 'Diallo',
            'proprietaire_prenom' => 'Mamadou',
            'proprietaire_telephone' => '622000001',
            'proprietaire_pays' => 'GN',
        ];
        $ligne = array_map(fn ($h) => $valeurs[$h] ?? '90', $entetes);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.store'), ['fichier' => $this->uploadFileAvecEntetesBrutes($entetes, [$ligne])])
            ->assertRedirect();

        $import = ImportFlotte::firstOrFail();
        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('doublon', mb_strtolower($import->rapport['groupes'][0]['erreurs'][0]));
        $this->assertStringContainsString('SACHET_EAU', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    /**
     * Parcours complet : Excel → import → vehicule_capacites → véhicule → commande de vente →
     * lignes produit → catégorie du produit → capacité du véhicule pour cette catégorie →
     * contrôle de quantité. Deux PRODUITS DIFFÉRENTS de la même catégorie "Bouteille d'eau"
     * (des formats de pack différents, pas des catégories différentes) doivent voir leurs
     * quantités CUMULÉES avant comparaison au plafond de la catégorie — jamais contrôlées
     * indépendamment ligne par ligne.
     */
    public function test_import_puis_vente_bloque_le_depassement_de_capacite_cumule_par_categorie(): void
    {
        $bouteilles = Categorie::create(['organization_id' => $this->org->id, 'nom' => "Bouteille d'eau", 'reference' => 'BOUTEILLE_EAU']);

        $import = $this->importerVehiculeEtChauffeur(['capacite__BOUTEILLE_EAU' => '150']);
        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 150]);

        $pack500 = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack 12 bouteilles 500ml', 'categorie_id' => $bouteilles->id], ['prix_vente' => 5000]);
        $pack350 = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack 15 bouteilles 350ml', 'categorie_id' => $bouteilles->id], ['prix_vente' => 5000]);
        $pack1500 = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack 6 bouteilles 1500ml', 'categorie_id' => $bouteilles->id], ['prix_vente' => 5000]);

        $vendeur = $this->makeUser(['imports-flotte.create', 'imports-flotte.read', 'ventes.read', 'ventes.create']);

        // 80 + 50 = 130 <= 150 : autorisé.
        $this->actingAs($vendeur)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $pack500->id, 'qte' => 80, 'prix_vente' => 5000],
                    ['produit_id' => $pack350->id, 'qte' => 50, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);

        // 80 + 50 + 30 = 160 > 150 : refusé — le plafond porte sur le CUMUL de la catégorie
        // "Bouteille d'eau", pas sur chaque ligne/format indépendamment.
        $this->actingAs($vendeur)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $pack500->id, 'qte' => 80, 'prix_vente' => 5000],
                    ['produit_id' => $pack350->id, 'qte' => 50, 'prix_vente' => 5000],
                    ['produit_id' => $pack1500->id, 'qte' => 30, 'prix_vente' => 5000],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_analyse_flags_error_for_invalid_capacite_sachets(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['capacite__SACHET_EAU' => 'abc']);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Capacité "SACHET_EAU" invalide', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    public function test_confirm_vehicule_sans_proprietaire_saisi_recoit_proprietaire_par_defaut(): void
    {
        // Cf. Proprietaire::interneParDefautId() : un véhicule importé sans propriétaire
        // renseigné reçoit le propriétaire interne configuré sur l'organisation par défaut,
        // au lieu de rester sans propriétaire.
        $defaut = $this->defaultInterneProprietaire();

        $import = $this->importer(
            [$this->ligneVehicule([
                'vehicule_categorie' => 'interne',
                'proprietaire_nom' => '', 'proprietaire_prenom' => '', 'proprietaire_telephone' => '', 'proprietaire_pays' => '',
            ])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertSame($defaut->id, $vehicule->proprietaire_id);
    }

    /**
     * Une ligne "interne" peut documenter explicitement le propriétaire interne par défaut
     * (mêmes nom/prénom/téléphone) au lieu de laisser les colonnes proprietaire_* vides — même
     * règle de cohérence que VehiculeController::ensureCategorieCoherente() côté formulaire.
     */
    public function test_analyse_accepte_vehicule_interne_dont_le_proprietaire_saisi_est_le_defaut(): void
    {
        $defaut = $this->defaultInterneProprietaire(['nom' => 'SIDIBE', 'prenom' => 'Moussa', 'telephone' => '+224622602693']);

        $import = $this->importer(
            [$this->ligneVehicule([
                'vehicule_categorie' => 'interne',
                'proprietaire_nom' => 'SIDIBE', 'proprietaire_prenom' => 'Moussa',
                'proprietaire_telephone' => '622602693', 'proprietaire_pays' => 'GN',
            ])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(0, $import->nb_groupes_erreur);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertSame($defaut->id, $vehicule->proprietaire_id);
    }

    /** Un véritable tiers (propriétaire différent du défaut) reste incohérent avec "interne". */
    public function test_analyse_refuse_vehicule_interne_avec_un_veritable_proprietaire_tiers(): void
    {
        $this->defaultInterneProprietaire();

        $import = $this->importer(
            [$this->ligneVehicule(['vehicule_categorie' => 'interne'])], // proprietaire_telephone => 622000001, un autre numéro
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString(
            'Véhicule interne : le propriétaire renseigné doit être le propriétaire interne par défaut',
            $import->rapport['groupes'][0]['erreurs'][0]
        );
    }

    /**
     * Aucun propriétaire interne configuré sur l'organisation (jamais deviné depuis le fichier,
     * même si toutes les lignes "interne" citent le même propriétaire) : l'import doit refuser
     * avec un message explicite plutôt que d'assigner silencieusement proprietaire_id = null.
     */
    public function test_analyse_refuse_vehicule_interne_sans_proprietaire_interne_configure(): void
    {
        $import = $this->importer(
            [$this->ligneVehicule([
                'vehicule_categorie' => 'interne',
                'proprietaire_nom' => '', 'proprietaire_prenom' => '', 'proprietaire_telephone' => '', 'proprietaire_pays' => '',
            ])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString(
            "Aucun propriétaire interne n'est configuré pour cette organisation",
            $import->rapport['groupes'][0]['erreurs'][0]
        );
    }

    public function test_confirm_creates_livreur_sans_nom_avec_designation_par_defaut(): void
    {
        // Ni livreur_nom_complet ni livreur_nom/livreur_prenom renseignés : jamais
        // de nom_complet vide en base, repli sur "Chauffeur-1 {véhicule}".
        $import = $this->importerVehiculeEtChauffeur([], [
            'livreur_nom' => '', 'livreur_prenom' => '',
        ]);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $livreur = Livreur::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+224623000001'))->firstOrFail();
        $this->assertSame('Chauffeur-1 Camion 1', $livreur->nom_complet);
    }

    public function test_confirm_numbers_designation_par_defaut_par_role(): void
    {
        // Deux convoyeurs sans nom sur le même véhicule → numérotés par position,
        // pas tous "Convoyeur-1".
        $import = $this->importer(
            [$this->ligneVehicule()],
            [
                $this->ligneLivreurChauffeur(),
                $this->ligneLivreurChauffeur([
                    'livreur_nom' => '', 'livreur_prenom' => '',
                    'livreur_telephone' => '623000002', 'livreur_role' => 'convoyeur',
                ]),
                $this->ligneLivreurChauffeur([
                    'livreur_nom' => '', 'livreur_prenom' => '',
                    'livreur_telephone' => '623000003', 'livreur_role' => 'convoyeur',
                ]),
            ]
        );

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $premier = Livreur::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+224623000002'))->firstOrFail();
        $second = Livreur::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+224623000003'))->firstOrFail();
        $this->assertSame('Convoyeur-1 Camion 1', $premier->nom_complet);
        $this->assertSame('Convoyeur-2 Camion 1', $second->nom_complet);
    }

    public function test_confirm_deactivates_already_active_vehicule_when_creating_draft_equipe(): void
    {
        // Véhicule déjà existant, actif, sans équipe (cas réel : créé manuellement
        // avant d'avoir une équipe assignée).
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
            'is_active' => true,
        ]);

        $import = $this->importerVehiculeEtChauffeur();
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertFalse($vehicule->fresh()->is_active);
    }

    public function test_confirm_creates_vehicule_without_equipe_when_no_livreur(): void
    {
        // La création du véhicule et celle de son équipe sont dissociées :
        // sans aucun livreur dans le fichier, aucune équipe (même brouillon)
        // ne doit être créée.
        $import = $this->importer([$this->ligneVehicule()], []);
        $this->assertNull($import->rapport['groupes'][0]['equipe']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $import->refresh();
        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1, $import->nb_proprietaires_crees);
        $this->assertSame(1, $import->nb_vehicules_crees);
        $this->assertSame(0, $import->nb_livreurs_crees);
        $this->assertSame(0, $import->nb_equipes_creees);

        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC-1234-A')->firstOrFail();
        $this->assertSame(0, EquipeLivraison::where('vehicule_id', $vehicule->id)->count());
        $this->assertFalse($vehicule->fresh()->is_active);
    }

    public function test_confirm_creates_several_vehicules_without_livreur_and_no_empty_equipe(): void
    {
        $import = $this->importer(
            [
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-1111-A', 'proprietaire_telephone' => '622000001']),
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-2222-B', 'proprietaire_telephone' => '622000002']),
            ],
            []
        );

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(2, $import->nb_vehicules_crees);
        $this->assertSame(0, $import->nb_equipes_creees);
        $this->assertSame(0, EquipeLivraison::where('organization_id', $this->org->id)->count());
    }

    public function test_confirm_does_not_create_equipe_for_existing_vehicule_without_equipe_or_livreur(): void
    {
        // Véhicule déjà existant, sans équipe, et le fichier ne contient aucune
        // ligne "livreurs" pour lui : ne rien créer (cf. cas 3 — absence de
        // ligne livreur = "ne pas modifier les affectations", jamais "en créer une").
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);

        $import = $this->importer([$this->ligneVehicule()], []);
        $this->assertNull($import->rapport['groupes'][0]['equipe']);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(0, $import->nb_vehicules_crees);
        $this->assertSame(0, $import->nb_equipes_creees);
        $this->assertSame(0, EquipeLivraison::where('organization_id', $this->org->id)->count());
    }

    public function test_analyse_counts_equipe_only_for_groups_with_at_least_one_livreur(): void
    {
        // Fichier mixte : 2 véhicules sans livreur + 1 véhicule avec un chauffeur.
        // Seul ce dernier groupe compte comme "équipe à créer".
        $import = $this->importer(
            [
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-1111-A', 'proprietaire_telephone' => '622000001']),
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-2222-B', 'proprietaire_telephone' => '622000002']),
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-3333-C', 'proprietaire_telephone' => '622000003']),
            ],
            [$this->ligneLivreurChauffeur(['vehicule_immatriculation' => 'RC-3333-C'])]
        );

        $this->assertSame(3, $import->nb_groupes_valides);
        $this->assertNull($import->rapport['groupes'][0]['equipe']);
        $this->assertNull($import->rapport['groupes'][1]['equipe']);
        $this->assertNotNull($import->rapport['groupes'][2]['equipe']);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(3, $import->nb_vehicules_crees);
        $this->assertSame(1, $import->nb_livreurs_crees);
        $this->assertSame(1, $import->nb_equipes_creees);
        $this->assertSame(1, EquipeLivraison::where('organization_id', $this->org->id)->count());

        // Aucune équipe sans membre n'est créée.
        $this->assertSame(0, EquipeLivraison::where('organization_id', $this->org->id)->doesntHave('membres')->count());
    }

    public function test_analyse_accepts_existing_vehicule_without_any_livreur_and_keeps_its_members(): void
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'commission_unitaire_par_pack' => 5000,
        ]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224623000001']);
        $equipe->membres()->create(['livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'montant_par_pack' => 3000]);

        $import = $this->importer([$this->ligneVehicule()], []);
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
            [$this->ligneVehicule()],
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

    public function test_confirm_creates_a_single_proprietaire_shared_by_several_vehicules_in_the_same_file(): void
    {
        // Un même propriétaire peut posséder plusieurs véhicules : deux lignes
        // "vehicules" du fichier partagent le même téléphone propriétaire, sans
        // qu'aucun des deux ne soit encore en base avant l'import.
        $import = $this->importer(
            [
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-1111-A']),
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-2222-B']),
            ],
            []
        );

        $this->assertSame(2, $import->nb_groupes_valides);
        $this->assertTrue($import->rapport['groupes'][0]['proprietaire']['existe'] === false);
        $this->assertFalse($import->rapport['groupes'][0]['proprietaire']['doublon_fichier']);
        $this->assertTrue($import->rapport['groupes'][1]['proprietaire']['doublon_fichier']);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(1, $import->nb_proprietaires_crees, 'un seul propriétaire doit être créé pour les deux véhicules');
        $this->assertSame(2, $import->nb_vehicules_crees);
        $this->assertSame(1, Proprietaire::where('organization_id', $this->org->id)->count());

        $proprietaire = Proprietaire::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->firstOrFail();
        $vehiculeA = Vehicule::where('immatriculation', 'RC-1111-A')->firstOrFail();
        $vehiculeB = Vehicule::where('immatriculation', 'RC-2222-B')->firstOrFail();
        $this->assertSame($proprietaire->id, $vehiculeA->proprietaire_id);
        $this->assertSame($proprietaire->id, $vehiculeB->proprietaire_id);
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
            [$this->ligneVehicule()],
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
                $this->ligneVehicule(),
                $this->ligneVehicule(['vehicule_immatriculation' => 'RC-9999-Z', 'proprietaire_telephone' => '622000099']),
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

    // ── normalisation tolérante ───────────────────────────────────────────────

    public function test_analyse_accepts_country_name_uppercase_with_accent(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_pays' => 'GUINÉE']);

        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_accepts_country_name_without_accent(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_pays' => 'guinee']);

        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_accepts_iso_country_code_case_insensitive(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_pays' => 'gn']);

        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_accepts_foreign_country_name(): void
    {
        $import = $this->importerVehiculeEtChauffeur([
            'proprietaire_pays' => 'BELGIQUE',
            'proprietaire_telephone' => '470123456',
        ]);

        $this->assertSame(0, $import->nb_groupes_erreur);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertTrue(
            Proprietaire::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+32470123456'))->exists()
        );
    }

    public function test_analyse_rejects_unknown_country_with_explicit_message(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_pays' => 'Narnia']);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Pays introuvable : "Narnia"', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    public function test_confirm_normalizes_phone_without_country_code(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_telephone' => '622000001']);
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertTrue(Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->exists());
    }

    public function test_confirm_normalizes_phone_with_leading_zero(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_telephone' => '0622000001']);
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertTrue(Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->exists());
    }

    public function test_confirm_normalizes_phone_with_224_prefix(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_telephone' => '224622000001']);
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertTrue(Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->exists());
    }

    public function test_confirm_normalizes_phone_with_plus_224_prefix(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_telephone' => '+224622000001']);
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertTrue(Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->exists());
    }

    public function test_confirm_normalizes_phone_with_00224_prefix(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_telephone' => '00224622000001']);
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertTrue(Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->exists());
    }

    public function test_confirm_normalizes_phone_with_spaces_and_dashes(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['proprietaire_telephone' => '622-00-00-01']);
        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));

        $this->assertTrue(Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000001'))->exists());
    }

    public function test_analyse_accepts_vehicule_type_with_different_case(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['vehicule_type' => 'TRICYCLE']);

        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_accepts_vehicule_type_with_spaces_around_dash(): void
    {
        TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Tricycle-70']);

        $import = $this->importerVehiculeEtChauffeur(['vehicule_type' => 'Tricycle - 70']);

        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_blocks_close_typo_on_vehicule_type_but_suggests_it(): void
    {
        TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Tricycle-70']);

        $import = $this->importerVehiculeEtChauffeur(['vehicule_type' => 'Ticyle-70']);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Tricycle-70', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    public function test_analyse_blocks_ambiguous_vehicule_type_without_guessing(): void
    {
        TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Tricycle-70']);
        TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Tricycle-90']);

        $import = $this->importerVehiculeEtChauffeur(['vehicule_type' => 'Tricycle-80']);

        $this->assertSame(1, $import->nb_groupes_erreur);
    }

    public function test_analyse_preserves_raw_value_in_error_message(): void
    {
        $import = $this->importerVehiculeEtChauffeur(['vehicule_type' => 'Camion XZ']);

        $this->assertStringContainsString('"Camion XZ"', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    public function test_analyse_accepts_site_with_different_case(): void
    {
        $this->defaultInterneProprietaire();

        $import = $this->importer(
            [$this->ligneVehicule([
                'vehicule_site' => 'MATOTO',
                'vehicule_categorie' => 'interne',
                'proprietaire_nom' => '', 'proprietaire_prenom' => '', 'proprietaire_telephone' => '', 'proprietaire_pays' => '',
            ])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_accepts_site_code_without_leading_zero(): void
    {
        // Site créé dans setUp() : premier site de l'organisation, code auto "001".
        $this->defaultInterneProprietaire();

        $import = $this->importer(
            [$this->ligneVehicule([
                'vehicule_site' => '1',
                'vehicule_categorie' => 'interne',
                'proprietaire_nom' => '', 'proprietaire_prenom' => '', 'proprietaire_telephone' => '', 'proprietaire_pays' => '',
            ])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->assertSame(0, $import->nb_groupes_erreur);
    }

    public function test_analyse_accepts_various_true_boolean_spellings(): void
    {
        foreach (['Oui', 'OUI', '1', 'true', 'x'] as $valeur) {
            $import = $this->importerVehiculeEtChauffeur(['vehicule_livraison_logistique' => $valeur]);
            $this->assertSame(0, $import->nb_groupes_erreur, "valeur testée : {$valeur}");
        }
    }

    public function test_analyse_accepts_various_false_boolean_spellings(): void
    {
        foreach (['Non', 'NON', '0', 'false'] as $valeur) {
            $import = $this->importerVehiculeEtChauffeur(['vehicule_livraison_logistique' => $valeur]);
            $this->assertSame(0, $import->nb_groupes_erreur, "valeur testée : {$valeur}");
        }
    }

    /**
     * Cas d'une cellule Excel de type booléen natif (pas du texte "oui"/"1") — cf.
     * ImportFlotteParser::toBool(), qui gère explicitement is_bool() pour ce cas.
     */
    public function test_analyse_accepts_native_boolean_cell_value(): void
    {
        $import = $this->importerVehiculeEtChauffeur([
            'vehicule_livraison_vente' => true,
            'vehicule_livraison_logistique' => false,
        ]);

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertTrue($import->rapport['groupes'][0]['vehicule']['livraison_vente']);
        $this->assertFalse($import->rapport['groupes'][0]['vehicule']['livraison_logistique']);
    }

    // ── mode "livreurs seulement" (TypeImportFlotte::LIVREURS) ─────────────────

    public function test_livreurs_seul_store_persists_type(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);

        $import = $this->importerLivreursSeul([$this->ligneLivreurChauffeur()]);

        $this->assertSame('livreurs', $import->type->value);
    }

    public function test_store_defaults_to_type_flotte_when_omitted(): void
    {
        $import = $this->importerVehiculeEtChauffeur();

        $this->assertSame('flotte', $import->type->value);
    }

    public function test_livreurs_seul_analyse_flags_error_when_vehicule_does_not_exist(): void
    {
        $import = $this->importerLivreursSeul([$this->ligneLivreurChauffeur()]);

        $this->assertSame(1, $import->nb_groupes_erreur);
        $this->assertStringContainsString('Aucun véhicule avec l\'immatriculation "RC-1234-A" en base', $import->rapport['groupes'][0]['erreurs'][0]);
    }

    public function test_livreurs_seul_analyse_attaches_livreur_to_existing_vehicule_without_creating_it(): void
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);

        $import = $this->importerLivreursSeul([$this->ligneLivreurChauffeur()]);

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertSame(1, $import->nb_groupes_valides);
        $this->assertTrue($import->rapport['groupes'][0]['vehicule']['existe']);
        $this->assertSame($vehicule->id, $import->rapport['groupes'][0]['vehicule']['id']);
        $this->assertNull($import->rapport['groupes'][0]['proprietaire']);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.confirm', $import))
            ->assertRedirect(route('imports-flotte.show', $import));

        $import->refresh();
        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(0, $import->nb_proprietaires_crees);
        $this->assertSame(0, $import->nb_vehicules_crees, 'le véhicule existant ne doit jamais être recréé');
        $this->assertSame(1, $import->nb_livreurs_crees);
        $this->assertSame(1, $import->nb_equipes_creees);

        $livreur = Livreur::where('organization_id', $this->org->id)->whereHas('personne', fn ($q) => $q->where('telephone', '+224623000001'))->firstOrFail();
        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->firstOrFail();
        $this->assertTrue($equipe->membres()->where('livreur_id', $livreur->id)->exists());

        // Aucune donnée du véhicule n'est modifiée par ce mode (aucune feuille
        // "vehicules" fournie, la ligne n'a jamais existé).
        $this->assertSame('RC-1234-A', $vehicule->fresh()->immatriculation);
    }

    public function test_livreurs_seul_adds_member_to_existing_equipe_without_touching_its_commission(): void
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'commission_unitaire_par_pack' => 5000,
        ]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224623000001']);
        $equipe->membres()->create(['livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'montant_par_pack' => 3000]);

        $import = $this->importerLivreursSeul([
            $this->ligneLivreurChauffeur([
                'livreur_nom' => 'Soumah', 'livreur_prenom' => 'Fatoumata',
                'livreur_telephone' => '623000002', 'livreur_role' => 'convoyeur',
            ]),
        ]);

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertTrue($import->rapport['groupes'][0]['equipe']['existe']);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $import->refresh();

        $this->assertSame('termine', $import->statut->value);
        $this->assertSame(0, $import->nb_equipes_creees, 'équipe déjà existante, pas recréée');
        $this->assertSame(1, $import->nb_livreurs_crees);
        $this->assertSame(2, $equipe->membres()->count());
        // L'équipe déjà configurée n'est pas remise à zéro par l'import.
        $this->assertSame('5000.00', $equipe->fresh()->commission_unitaire_par_pack);
    }

    public function test_livreurs_seul_analyse_flags_error_for_invalid_phone(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);

        $import = $this->importerLivreursSeul([
            $this->ligneLivreurChauffeur(['livreur_telephone' => '12345']),
        ]);

        $this->assertSame(1, $import->nb_groupes_erreur);
    }

    public function test_livreurs_seul_analyse_consolidates_multi_vehicule_conflict_into_one_group(): void
    {
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'immatriculation' => 'RC-1111-A', 'type_vehicule_id' => $this->type->id]);
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'immatriculation' => 'RC-2222-B', 'type_vehicule_id' => $this->type->id]);

        $import = $this->importerLivreursSeul([
            $this->ligneLivreurChauffeur(['vehicule_immatriculation' => 'RC-1111-A']),
            $this->ligneLivreurChauffeur(['vehicule_immatriculation' => 'RC-2222-B']),
        ]);

        // Comme en mode "flotte" : un seul groupe d'erreur pour le conflit, pas
        // un par véhicule concerné. Contrairement au mode "flotte" (où le
        // véhicule reste un groupe valide séparé, ancré par sa propre ligne
        // "vehicules"), ici les deux véhicules n'ont plus aucune ligne "livreurs"
        // à eux une fois la ligne litigieuse exclue — il n'y a donc rien d'autre
        // à rapporter pour eux.
        $this->assertSame(0, $import->nb_groupes_valides);
        $this->assertSame(1, $import->nb_groupes_erreur);

        $groupeErreur = collect($import->rapport['groupes'])->firstWhere('statut', 'erreur');
        $this->assertStringContainsString('RC-1111-A', $groupeErreur['erreurs'][0]);
        $this->assertStringContainsString('RC-2222-B', $groupeErreur['erreurs'][0]);
    }

    public function test_livreurs_seul_ignores_vehicules_sheet_if_present(): void
    {
        // Même si l'utilisateur laisse une feuille "vehicules" dans le fichier
        // (ex: modèle "flotte" réutilisé par erreur), le mode "livreurs" ne la
        // lit jamais : seule la feuille "livreurs" compte.
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-1234-A',
            'type_vehicule_id' => $this->type->id,
        ]);

        $fichier = $this->uploadFile(
            [$this->ligneVehicule(['vehicule_immatriculation' => 'RC-9999-Z'])],
            [$this->ligneLivreurChauffeur()]
        );

        $this->actingAs($this->user)
            ->post(route('imports-flotte.store'), ['type' => 'livreurs', 'fichier' => $fichier])
            ->assertRedirect();

        $import = ImportFlotte::firstOrFail();
        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertSame(1, $import->nb_groupes_valides);

        $this->actingAs($this->user)->post(route('imports-flotte.confirm', $import));
        $this->assertSame(0, Vehicule::where('immatriculation', 'RC-9999-Z')->count(), 'la feuille "vehicules" ne doit jamais être exploitée dans ce mode');
    }

    public function test_livreurs_seul_template_download(): void
    {
        $this->actingAs($this->user)
            ->get(route('imports-flotte.template', ['type' => 'livreurs']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * Le gabarit "vehicules" téléchargeable contient une colonne "capacite__<REFERENCE>" par
     * catégorie du catalogue de l'organisation — généré dynamiquement à la demande (cf.
     * ImportFlotteController::template(), ImportFlotteVehiculesSheetExport), jamais figé aux
     * deux colonnes fixes de l'ancienne convention.
     */
    public function test_flotte_template_download_contient_une_colonne_par_categorie(): void
    {
        Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_EAU']);
        Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'reference' => 'BOUTEILLE_EAU']);

        $response = $this->actingAs($this->user)
            ->get(route('imports-flotte.template'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmpPath = tempnam(sys_get_temp_dir(), 'template_flotte_test').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());

        $spreadsheet = IOFactory::load($tmpPath);
        $entetes = $spreadsheet->getSheetByName('vehicules')->rangeToArray('A1:Z1', null, true, true, false)[0];

        $this->assertContains('capacite__SACHET_EAU', $entetes);
        $this->assertContains('capacite__BOUTEILLE_EAU', $entetes);
    }

    public function test_analyse_still_accepts_an_already_valid_file(): void
    {
        // Non-régression : un fichier déjà au format canonique ne doit générer
        // aucune erreur nouvelle après l'ajout de la normalisation.
        $import = $this->importerVehiculeEtChauffeur();

        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertSame(1, $import->nb_groupes_valides);
    }

    /**
     * Régression : un onglet renommé "Véhicules" (accentué, capitalisé — comme
     * partout ailleurs dans l'UI de l'appli) doit être reconnu au même titre que
     * "vehicules". Avant fix, la feuille "vehicules" semblait introuvable
     * (trouverFeuille comparait sans passer par ImportTextNormalizer) : toutes
     * les lignes "livreurs" échouaient avec un message d'immatriculation
     * "introuvable" trompeur, alors que la ligne véhicule existait bien.
     */
    public function test_analyse_accepts_sheet_titled_with_accent_and_uppercase(): void
    {
        $spreadsheet = new Spreadsheet;

        $sheetVehicules = $spreadsheet->getActiveSheet();
        $sheetVehicules->setTitle('Véhicules');
        $sheetVehicules->fromArray(self::HEADERS_VEHICULES, null, 'A1');
        $sheetVehicules->fromArray(
            array_map(fn ($h) => $this->ligneVehicule()[$h] ?? '', self::HEADERS_VEHICULES),
            null,
            'A2'
        );

        $sheetLivreurs = $spreadsheet->createSheet();
        $sheetLivreurs->setTitle('livreurs');
        $sheetLivreurs->fromArray(self::HEADERS_LIVREURS, null, 'A1');
        $sheetLivreurs->fromArray(
            array_map(fn ($h) => $this->ligneLivreurChauffeur()[$h] ?? '', self::HEADERS_LIVREURS),
            null,
            'A2'
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_flotte_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $fichier = new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->user)
            ->post(route('imports-flotte.store'), ['fichier' => $fichier])
            ->assertRedirect();

        $import = ImportFlotte::firstOrFail();
        $this->assertSame(0, $import->nb_groupes_erreur);
        $this->assertSame(1, $import->nb_groupes_valides);
    }
}
