<?php

namespace Tests\Feature;

use App\Enums\StatutImportVehiculesMaj;
use App\Models\Categorie;
use App\Models\ImportVehiculesMaj;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Import de mise à jour en masse des véhicules — entièrement séparé de l'import flotte (création,
 * cf. ImportFlotteTest) : jamais de création de véhicule ici, seuls le site, les capacités par
 * catégorie et les usages vente/logistique sont modifiables (cf. ImportVehiculesMajParser).
 */
class ImportVehiculesMajTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private Categorie $categorieSachet;

    private Categorie $categorieBouteille;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->initOrgAndUser([
            'vehicules.read', 'vehicules.update',
            'imports-vehicules-maj.create', 'imports-vehicules-maj.read',
        ]);

        // "Site Principal" déjà créé et rattaché par initOrgAndUser().
        $this->siteA = Site::where('organization_id', $this->org->id)->firstOrFail();
        $this->siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Sonfonia', 'type' => 'depot']);

        $this->categorieSachet = Categorie::create([
            'organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'reference' => 'SACHET_DEAU',
        ]);
        $this->categorieBouteille = Categorie::create([
            'organization_id' => $this->org->id, 'nom' => 'Bouteille eau', 'reference' => 'BOUTEILLE_DEAU',
        ]);
    }

    private function makeVehicule(array $overrides = []): Vehicule
    {
        $typeVehicule = TypeVehicule::where('organization_id', $this->org->id)->firstOrFail();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create(array_replace([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'proprietaire_id' => $proprietaire->id,
            'site_id' => $this->siteA->id,
            'nom_vehicule' => 'Camion Original',
            'marque' => 'Toyota',
            'modele' => 'Hilux',
            'immatriculation' => 'RC-1234-A',
            'livraison_vente' => true,
            'livraison_logistique' => false,
        ], $overrides));
    }

    /** @return string[] */
    private function entetesDe(array $lignes): array
    {
        $entetes = [];
        foreach ($lignes as $ligne) {
            foreach (array_keys($ligne) as $cle) {
                if (! in_array($cle, $entetes, true)) {
                    $entetes[] = $cle;
                }
            }
        }

        return $entetes;
    }

    private function uploadFile(array $lignes, ?array $entetes = null): UploadedFile
    {
        $entetes ??= $this->entetesDe($lignes);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('vehicules');
        $sheet->fromArray($entetes, null, 'A1');
        foreach ($lignes as $i => $ligne) {
            $row = array_map(fn ($h) => $ligne[$h] ?? '', $entetes);
            $sheet->fromArray($row, null, 'A'.($i + 2));
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_vehicules_maj_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile($tmpPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importer(array $lignes, ?array $entetes = null): ImportVehiculesMaj
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.imports-maj.store'), ['fichier' => $this->uploadFile($lignes, $entetes)])
            ->assertRedirect();

        return ImportVehiculesMaj::firstOrFail();
    }

    private function confirmer(ImportVehiculesMaj $import): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.imports-maj.confirm', $import))
            ->assertRedirect();
    }

    // ── Champs autorisés ─────────────────────────────────────────────────────

    public function test_changement_de_site(): void
    {
        $vehicule = $this->makeVehicule(['site_id' => $this->siteA->id]);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_site' => $this->siteB->nom,
        ]]);

        $this->assertSame(StatutImportVehiculesMaj::ANALYSE, $import->statut);
        $this->assertSame(1, $import->nb_lignes_maj);
        $this->assertSame(0, $import->nb_lignes_erreur);
        $this->assertTrue($import->estPret());

        $this->confirmer($import);

        $vehicule->refresh();
        $import->refresh();
        $this->assertSame($this->siteB->id, $vehicule->site_id);
        $this->assertSame(StatutImportVehiculesMaj::TERMINE, $import->statut);
        $this->assertSame(1, $import->nb_vehicules_mis_a_jour);
    }

    public function test_conservation_du_site_si_cellule_vide(): void
    {
        $vehicule = $this->makeVehicule(['site_id' => $this->siteA->id, 'livraison_logistique' => false]);

        // Cellule site vide : ne doit jamais devenir NULL ni être remise à zéro — on change
        // volontairement un autre champ (logistique) pour que la ligne soit bien "à mettre à
        // jour" et confirmable.
        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_site' => '',
            'vehicule_livraison_logistique' => 'oui',
        ]]);

        $this->confirmer($import);

        $vehicule->refresh();
        $this->assertSame($this->siteA->id, $vehicule->site_id);
        $this->assertTrue($vehicule->livraison_logistique);
    }

    public function test_changement_des_deux_capacites(): void
    {
        $vehicule = $this->makeVehicule();
        VehiculeCapacite::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'categorie_id' => $this->categorieSachet->id,
            'capacite_max' => 50,
        ]);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'capacite__SACHET_DEAU' => '100',
            'capacite__BOUTEILLE_DEAU' => '60',
        ]]);

        $this->assertSame(1, $import->nb_lignes_maj);
        $this->confirmer($import);

        $this->assertSame(100, VehiculeCapacite::where('vehicule_id', $vehicule->id)
            ->where('categorie_id', $this->categorieSachet->id)->value('capacite_max'));
        $this->assertSame(60, VehiculeCapacite::where('vehicule_id', $vehicule->id)
            ->where('categorie_id', $this->categorieBouteille->id)->value('capacite_max'));
    }

    public function test_changement_vente(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true]);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_livraison_vente' => 'non',
        ]]);
        $this->confirmer($import);

        $this->assertFalse($vehicule->refresh()->livraison_vente);
    }

    public function test_changement_logistique(): void
    {
        $vehicule = $this->makeVehicule(['livraison_logistique' => false]);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_livraison_logistique' => 'oui',
        ]]);
        $this->confirmer($import);

        $this->assertTrue($vehicule->refresh()->livraison_logistique);
    }

    public function test_plusieurs_modifications_sur_une_meme_ligne(): void
    {
        $vehicule = $this->makeVehicule([
            'site_id' => $this->siteA->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
        ]);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_site' => $this->siteB->nom,
            'capacite__SACHET_DEAU' => '80',
            'vehicule_livraison_vente' => 'non',
            'vehicule_livraison_logistique' => 'oui',
        ]]);

        $this->assertCount(4, $import->rapport['lignes'][0]['changements']);

        $this->confirmer($import);

        $vehicule->refresh();
        $this->assertSame($this->siteB->id, $vehicule->site_id);
        $this->assertFalse($vehicule->livraison_vente);
        $this->assertTrue($vehicule->livraison_logistique);
        $this->assertSame(80, VehiculeCapacite::where('vehicule_id', $vehicule->id)
            ->where('categorie_id', $this->categorieSachet->id)->value('capacite_max'));
    }

    public function test_ligne_sans_modification(): void
    {
        $vehicule = $this->makeVehicule(['site_id' => $this->siteA->id, 'livraison_vente' => true]);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_site' => $this->siteA->nom,
            'vehicule_livraison_vente' => 'oui',
        ]]);

        $this->assertSame(0, $import->nb_lignes_maj);
        $this->assertSame(1, $import->nb_lignes_inchange);
        $this->assertSame(0, $import->nb_lignes_erreur);
        $this->assertSame('inchange', $import->rapport['lignes'][0]['statut']);

        $this->confirmer($import);
        $this->assertSame(0, $import->fresh()->nb_vehicules_mis_a_jour);
        $this->assertSame(StatutImportVehiculesMaj::TERMINE, $import->fresh()->statut);
    }

    // ── Erreurs et isolation ─────────────────────────────────────────────────

    public function test_immatriculation_inexistante(): void
    {
        $import = $this->importer([[
            'vehicule_immatriculation' => 'ZZ-0000-Z',
            'vehicule_site' => $this->siteB->nom,
        ]]);

        $this->assertSame(1, $import->nb_lignes_erreur);
        $this->assertFalse($import->estPret());
        $this->assertStringContainsString(
            "Aucun véhicule avec l'immatriculation",
            $import->rapport['lignes'][0]['erreurs'][0]
        );

        $this->actingAs($this->user)
            ->post(route('vehicules.imports-maj.confirm', $import))
            ->assertStatus(422);
    }

    public function test_multi_tenant_impossibilite_de_modifier_un_vehicule_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreType = TypeVehicule::factory()->create(['organization_id' => $autreOrg->id]);
        $autreProprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Site Externe', 'type' => 'depot']);
        $vehiculeAutreOrg = Vehicule::factory()->create([
            'organization_id' => $autreOrg->id,
            'type_vehicule_id' => $autreType->id,
            'proprietaire_id' => $autreProprietaire->id,
            'site_id' => $autreSite->id,
            'immatriculation' => 'AO-9999-A',
        ]);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehiculeAutreOrg->immatriculation,
            'vehicule_site' => $this->siteB->nom,
        ]]);

        // Traitée exactement comme une immatriculation inexistante — jamais trouvée, jamais
        // modifiable en dehors de l'organisation courante.
        $this->assertSame(1, $import->nb_lignes_erreur);

        $this->actingAs($this->user)
            ->post(route('vehicules.imports-maj.confirm', $import))
            ->assertStatus(422);

        $this->assertSame($autreSite->id, $vehiculeAutreOrg->fresh()->site_id);
    }

    public function test_tentative_de_modification_dun_champ_non_autorise(): void
    {
        $vehicule = $this->makeVehicule(['nom_vehicule' => 'Camion Original', 'site_id' => $this->siteA->id]);

        // "vehicule_nom" n'est jamais lu par le parseur — une vraie mise à jour (site) est
        // incluse pour que la ligne soit confirmable et que le test ne repose pas seulement sur
        // l'absence d'erreur.
        $import = $this->importer(
            [[
                'vehicule_immatriculation' => $vehicule->immatriculation,
                'vehicule_nom' => 'Nom Falsifié',
                'vehicule_site' => $this->siteB->nom,
            ]],
        );

        $this->confirmer($import);

        $vehicule->refresh();
        $this->assertSame('Camion Original', $vehicule->nom_vehicule);
        $this->assertSame($this->siteB->id, $vehicule->site_id);
    }

    public function test_aucune_donnee_sensible_du_vehicule_nest_modifiee(): void
    {
        $typeVehicule = TypeVehicule::where('organization_id', $this->org->id)->firstOrFail();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule([
            'nom_vehicule' => 'Camion Sensible',
            'marque' => 'Toyota',
            'modele' => 'Hilux',
            'type_vehicule_id' => $typeVehicule->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'partenaire',
        ]);
        $etatAvant = $vehicule->only(['nom_vehicule', 'marque', 'modele', 'type_vehicule_id', 'proprietaire_id', 'categorie', 'immatriculation']);

        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_site' => $this->siteB->nom,
            'capacite__SACHET_DEAU' => '90',
            'vehicule_livraison_vente' => 'non',
            'vehicule_livraison_logistique' => 'oui',
        ]]);
        $this->confirmer($import);

        $vehicule->refresh();
        $etatApres = $vehicule->only(['nom_vehicule', 'marque', 'modele', 'type_vehicule_id', 'proprietaire_id', 'categorie', 'immatriculation']);
        $this->assertSame($etatAvant, $etatApres);
    }

    public function test_export_puis_modification_puis_reimport(): void
    {
        $vehicule = $this->makeVehicule([
            'site_id' => $this->siteA->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
        ]);
        VehiculeCapacite::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'categorie_id' => $this->categorieSachet->id,
            'capacite_max' => 75,
        ]);

        $response = $this->actingAs($this->user)->get(route('vehicules.export-maj'));
        $response->assertStatus(200);

        $tmpPath = tempnam(sys_get_temp_dir(), 'export_vehicules_maj_test').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheetByName('vehicules');
        $this->assertNotNull($sheet);

        $tableau = $sheet->toArray(null, true, true, false);
        $entetes = $tableau[0];
        $this->assertContains('vehicule_immatriculation', $entetes);
        $this->assertContains('capacite__SACHET_DEAU', $entetes);
        $this->assertContains('capacite__BOUTEILLE_DEAU', $entetes);

        $indexImmat = array_search('vehicule_immatriculation', $entetes, true);
        $ligneIndex = null;
        foreach ($tableau as $i => $ligne) {
            if ($i !== 0 && ($ligne[$indexImmat] ?? null) === $vehicule->immatriculation) {
                $ligneIndex = $i;
                break;
            }
        }
        $this->assertNotNull($ligneIndex);

        $ligneExportee = array_combine($entetes, $tableau[$ligneIndex]);
        $this->assertSame($this->siteA->nom, $ligneExportee['vehicule_site']);
        $this->assertSame('75', (string) $ligneExportee['capacite__SACHET_DEAU']);
        $this->assertSame('oui', $ligneExportee['vehicule_livraison_vente']);
        $this->assertSame('non', $ligneExportee['vehicule_livraison_logistique']);

        // Modification comme le ferait un utilisateur dans Excel, puis réimport tel quel — même
        // ordre de colonnes, aucune réorganisation nécessaire.
        $ligneExportee['vehicule_site'] = $this->siteB->nom;
        $ligneExportee['capacite__SACHET_DEAU'] = '120';

        $import = $this->importer([$ligneExportee], $entetes);
        $this->assertSame(0, $import->nb_lignes_erreur);
        $this->assertSame(1, $import->nb_lignes_maj);

        $this->confirmer($import);

        $vehicule->refresh();
        $this->assertSame($this->siteB->id, $vehicule->site_id);
        $this->assertSame(120, VehiculeCapacite::where('vehicule_id', $vehicule->id)
            ->where('categorie_id', $this->categorieSachet->id)->value('capacite_max'));
    }

    public function test_utilisateur_avec_lecture_seule_ne_peut_pas_confirmer(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'imports-vehicules-maj.read', 'guard_name' => 'web']);

        $lecteur = User::factory()->create(['organization_id' => $this->org->id]);
        $lecteur->assignRole('manager');
        $lecteur->givePermissionTo(['imports-vehicules-maj.read']);
        $lecteur->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        $vehicule = $this->makeVehicule();
        $import = $this->importer([[
            'vehicule_immatriculation' => $vehicule->immatriculation,
            'vehicule_site' => $this->siteB->nom,
        ]]);

        $this->actingAs($lecteur)
            ->get(route('vehicules.imports-maj.show', $import))
            ->assertStatus(200);

        $this->actingAs($lecteur)
            ->post(route('vehicules.imports-maj.confirm', $import))
            ->assertStatus(403);
    }
}
