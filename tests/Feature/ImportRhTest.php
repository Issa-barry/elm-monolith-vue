<?php

namespace Tests\Feature;

use App\Enums\StatutImportRh;
use App\Models\AuditLog;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\EmployeAffectation;
use App\Models\FonctionRh;
use App\Models\ImportRh;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\ServiceRh;
use App\Models\Site;
use App\Models\User;
use App\Services\ImportRh\ImportRhParser;
use App\Services\Rh\ContratService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\DesigneUnPdg;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Import de mise à jour des dossiers et contrats RH (docs/import-dossiers-rh.md) : jamais de
 * création de salarié, jamais de modification d'un contrat actif, salaire protégé partout.
 */
class ImportRhTest extends TestCase
{
    use DesigneUnPdg, HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const COMPLET = [
        'rh-employes.read', 'rh-employes.update', 'rh-contrats.create',
        'rh-remunerations.read', 'imports-rh.create', 'imports-rh.read',
    ];

    private Site $site;

    private ServiceRh $logistique;

    private FonctionRh $gerant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->initOrgAndUser(self::COMPLET);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
        $this->logistique = ServiceRh::create(['organization_id' => $this->org->id, 'libelle' => 'Logistique', 'code' => 'LOG']);
        $this->gerant = FonctionRh::create(['organization_id' => $this->org->id, 'libelle' => 'Gérant de dépôt', 'code' => 'GDD', 'is_active' => true]);
        // Signataire des contrats (ADR 0048) : sans lui, l'import ne crée aucun contrat.
        $this->designerPdg($this->org);
    }

    // ── Aides ─────────────────────────────────────────────────────────────────

    private function salarie(string $matricule, ?Site $site = null, array $attributs = [], ?Organization $org = null): Employe
    {
        $org ??= $this->org;
        $personne = Personne::create(['organization_id' => $org->id, 'nom' => 'CAMARA', 'prenom' => 'Fanta']);

        return Employe::create([
            'organization_id' => $org->id,
            'personne_id' => $personne->id,
            'matricule' => $matricule,
            'type_employe' => 'interne',
            'statut' => 'actif',
            'site_id' => ($site ?? $this->site)->id,
            ...$attributs,
        ]);
    }

    private function fichier(array $lignes, ?array $entetes = null): UploadedFile
    {
        $entetes ??= array_values(array_unique(array_merge(...array_map('array_keys', $lignes))));
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('employes');
        $sheet->fromArray($entetes, null, 'A1');
        foreach ($lignes as $i => $ligne) {
            $sheet->fromArray(array_map(fn ($h) => $ligne[$h] ?? '', $entetes), null, 'A'.($i + 2));
        }

        $chemin = tempnam(sys_get_temp_dir(), 'import_rh_test').'.xlsx';
        (new Xlsx($spreadsheet))->save($chemin);

        return new UploadedFile($chemin, 'dossiers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function deposer(array $lignes, ?User $user = null): ImportRh
    {
        $this->actingAs($user ?? $this->user)
            ->post(route('employes.imports.store'), ['fichier' => $this->fichier($lignes)])
            ->assertRedirect();

        // Par identifiant (ULID, croissant) : deux dépôts dans la même seconde ont le même created_at.
        return ImportRh::orderByDesc('id')->firstOrFail();
    }

    private function confirmer(ImportRh $import, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->post(route('employes.imports.confirm', $import));
    }

    private function utilisateur(array $permissions, string $role = 'comptable', ?Site $site = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $user->sites()->attach(($site ?? $this->site)->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function ligne(ImportRh $import, string $matricule): array
    {
        return collect($import->fresh()->rapport['lignes'])->firstWhere('matricule', $matricule);
    }

    // ── Aperçu et application ─────────────────────────────────────────────────

    public function test_l_import_met_a_jour_le_dossier_et_cree_le_contrat_apres_confirmation(): void
    {
        $employe = $this->salarie('004213');

        $import = $this->deposer([[
            'matricule' => '004213',
            'date_embauche' => '01/03/2024',
            'service' => 'Logistique',
            'fonction' => 'GDD',
            'contrat_type' => 'CDI',
            'contrat_date_debut' => '01/03/2024',
            'salaire_base' => '2 500 000',
        ]]);

        // Aperçu : rien n'est encore écrit.
        $this->assertSame(StatutImportRh::ANALYSE, $import->statut);
        $this->assertSame(1, $import->nb_lignes_maj);
        $this->assertNull($employe->fresh()->date_embauche);
        $this->assertSame(0, Contrat::count());

        $this->confirmer($import)->assertSessionHasNoErrors();

        $employe->refresh();
        $this->assertSame('2024-03-01', $employe->date_embauche->toDateString());
        $this->assertSame($this->logistique->id, $employe->service_rh_id);
        $this->assertSame($this->gerant->id, $employe->fonction_rh_id);
        $this->assertTrue(EmployeAffectation::where('employe_id', $employe->id)->where('fonction_rh_id', $this->gerant->id)->where('motif', 'import_rh')->exists());

        $contrat = Contrat::where('employe_id', $employe->id)->firstOrFail();
        $this->assertSame('actif', $contrat->statut_contrat->value);
        $this->assertEquals(2_500_000, (float) $contrat->salaire_base);

        $import->refresh();
        $this->assertSame(StatutImportRh::TERMINE, $import->statut);
        $this->assertSame(1, $import->nb_contrats_crees);
        $this->assertNull($import->fichier_path, 'fichier déposé supprimé');
        $this->assertSame([], Storage::disk('local')->allFiles('imports-rh'));
    }

    public function test_un_matricule_devenu_nombre_dans_excel_retrouve_ses_zeros(): void
    {
        $this->salarie('004213');

        $import = $this->deposer([['matricule' => 4213, 'date_embauche' => '01/03/2024']]);

        $this->assertSame('mise_a_jour', $this->ligne($import, '004213')['statut']);
    }

    public function test_cellule_vide_conserve_la_valeur_et_ligne_inchangee(): void
    {
        $employe = $this->salarie('000001', null, ['date_embauche' => '2020-01-01', 'service_rh_id' => $this->logistique->id]);

        $import = $this->deposer([['matricule' => '000001', 'date_embauche' => '', 'service' => '']]);

        $this->assertSame('inchange', $this->ligne($import, '000001')['statut']);
        $this->assertSame('2020-01-01', $employe->fresh()->date_embauche->toDateString());
    }

    // ── Règles bloquantes ─────────────────────────────────────────────────────

    public function test_les_erreurs_bloquent_la_confirmation_de_tout_le_fichier(): void
    {
        $valide = $this->salarie('000001');
        $this->salarie('000002');

        $import = $this->deposer([
            ['matricule' => '000001', 'date_embauche' => '01/03/2024'],
            ['matricule' => '999999', 'date_embauche' => '01/03/2024'],
        ]);

        $this->assertSame(1, $import->nb_lignes_erreur);
        $this->assertStringContainsString('introuvable', $this->ligne($import, '999999')['erreurs'][0]);

        $this->confirmer($import)->assertStatus(422);
        $this->assertNull($valide->fresh()->date_embauche, 'rien n\'est appliqué');
    }

    public function test_regles_de_ligne(): void
    {
        $this->salarie('000001');
        $this->salarie('000002');
        $this->salarie('000003');
        $this->salarie('000004');
        $this->salarie('000005', null, ['statut' => 'sorti']);
        ServiceRh::create(['organization_id' => $this->org->id, 'libelle' => 'Commercial', 'code' => 'COM', 'is_active' => false]);

        $import = $this->deposer([
            ['matricule' => '000001', 'service' => 'Logistiqe'],
            ['matricule' => '000002', 'service' => 'Commercial'],
            ['matricule' => '000003', 'contrat_type' => 'CDD', 'contrat_date_debut' => '01/01/2025'],
            ['matricule' => '000004', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2025', 'contrat_date_fin' => '31/12/2025'],
            ['matricule' => '000005', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2025'],
            ['matricule' => '000006', 'date_embauche' => '31/02/2024'],
            ['matricule' => '000006', 'date_embauche' => '01/02/2024'],
        ]);

        $this->assertStringContainsString('vouliez-vous dire « Logistique »', $this->ligne($import, '000001')['erreurs'][0]);
        $this->assertStringContainsString('désactivé', $this->ligne($import, '000002')['erreurs'][0]);
        $this->assertStringContainsString('CDD doit avoir une date de fin', $this->ligne($import, '000003')['erreurs'][0]);
        $this->assertStringContainsString('CDI n\'a pas de date de fin', $this->ligne($import, '000004')['erreurs'][0]);
        $this->assertStringContainsString('sorti', $this->ligne($import, '000005')['erreurs'][0]);
        $this->assertStringContainsString('plusieurs lignes', $this->ligne($import, '000006')['erreurs'][0]);
        $this->assertSame(0, ServiceRh::where('libelle', 'Logistiqe')->count(), 'aucun référentiel créé');
    }

    public function test_un_contrat_actif_existant_n_est_jamais_modifie(): void
    {
        $employe = $this->salarie('000001');
        $contrat = Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2023-01-01', 'salaire_base' => 1_800_000, 'statut_contrat' => 'actif',
        ]);

        // Identique : inchangé.
        $import = $this->deposer([['matricule' => '000001', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2023', 'salaire_base' => 1800000]]);
        $this->assertSame('inchange', $this->ligne($import, '000001')['statut']);

        // Différent : erreur, sans montant dans le message.
        $import = $this->deposer([['matricule' => '000001', 'contrat_type' => 'CDD', 'contrat_date_debut' => '01/01/2024', 'contrat_date_fin' => '31/12/2024']]);
        $this->assertStringContainsString('écran Contrats', $this->ligne($import, '000001')['erreurs'][0]);

        // Salaire différent : jamais remplacé, et aucun montant cité.
        $import = $this->deposer([['matricule' => '000001', 'salaire_base' => 2000000]]);
        $erreur = $this->ligne($import, '000001')['erreurs'][0];
        $this->assertStringContainsString('ne modifie jamais un contrat existant', $erreur);
        $this->assertStringNotContainsString('1 800 000', $erreur);
        $this->assertStringNotContainsString('2000000', $erreur);

        $this->assertEquals(1_800_000, (float) $contrat->fresh()->salaire_base);
        $this->assertSame(1, Contrat::count());
    }

    /**
     * Revue du 27/10/2026 : un contrat actif existant n'est jamais modifié, salaire compris (D3).
     * L'import complétait auparavant le salaire d'un contrat qui n'en avait pas.
     */
    public function test_un_contrat_actif_sans_salaire_n_est_pas_complete_par_l_import(): void
    {
        $employe = $this->salarie('000001');
        $contrat = Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2023-01-01', 'statut_contrat' => 'actif',
        ]);

        $import = $this->deposer([['matricule' => '000001', 'salaire_base' => 1500000]]);

        $this->assertStringContainsString('écran Contrats', $this->ligne($import, '000001')['erreurs'][0]);
        $this->confirmer($import)->assertStatus(422);
        $this->assertNull($contrat->fresh()->salaire_base);
    }

    public function test_le_journal_d_une_creation_de_contrat_ne_contient_aucun_montant(): void
    {
        $employe = $this->salarie('000001');
        $import = $this->deposer([['matricule' => '000001', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2025', 'salaire_base' => 1500000]]);
        $this->confirmer($import)->assertSessionHasNoErrors();

        $contrat = Contrat::where('employe_id', $employe->id)->firstOrFail();
        $journal = AuditLog::where('auditable_id', $contrat->id)->firstOrFail();
        $this->assertSame($import->id, $journal->meta['import_id']);
        $this->assertSame('renseigné', $journal->new_values['salaire_base']);
        $this->assertStringNotContainsString('1500000', json_encode([$journal->old_values, $journal->new_values, $journal->meta]));
    }

    // ── Nouvelle analyse au moment de confirmer ───────────────────────────────

    public function test_un_contrat_cree_entre_l_apercu_et_la_confirmation_annule_tout(): void
    {
        $employe = $this->salarie('000001');
        $autre = $this->salarie('000002');
        $import = $this->deposer([
            ['matricule' => '000001', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2025'],
            ['matricule' => '000002', 'date_embauche' => '01/02/2024'],
        ]);

        Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdd',
            'date_debut' => '2025-01-01', 'date_fin' => '2025-06-30', 'statut_contrat' => 'actif',
        ]);

        $this->confirmer($import);

        $this->assertSame(StatutImportRh::ECHOUE, $import->fresh()->statut);
        $this->assertNull($autre->fresh()->date_embauche, 'tout ou rien');
        $this->assertSame(1, Contrat::count());
        $this->assertNull($import->fresh()->fichier_path);
    }

    public function test_une_double_confirmation_est_refusee(): void
    {
        $this->salarie('000001');
        $import = $this->deposer([['matricule' => '000001', 'date_embauche' => '01/02/2024']]);

        $this->confirmer($import)->assertSessionHasNoErrors();
        $this->confirmer($import)->assertStatus(422);

        $this->assertSame(1, AuditLog::where('meta->import_id', $import->id)->count());
    }

    public function test_reimporter_un_fichier_deja_traite_ne_change_rien(): void
    {
        $this->salarie('000001');
        $lignes = [['matricule' => '000001', 'date_embauche' => '01/02/2024', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/02/2024']];

        $this->confirmer($this->deposer($lignes));
        $second = $this->deposer($lignes);

        $this->assertSame('inchange', $this->ligne($second, '000001')['statut']);
        $this->assertSame(1, Contrat::count());
    }

    public function test_abandonner_supprime_le_fichier_sans_rien_appliquer(): void
    {
        $employe = $this->salarie('000001');
        $import = $this->deposer([['matricule' => '000001', 'date_embauche' => '01/02/2024']]);

        $this->actingAs($this->user)->post(route('employes.imports.abandon', $import))->assertRedirect();

        $this->assertSame(StatutImportRh::ABANDONNE, $import->fresh()->statut);
        $this->assertNull($import->fresh()->fichier_path);
        $this->assertNull($employe->fresh()->date_embauche);
        $this->confirmer($import)->assertStatus(422);
    }

    // ── Salaires protégés ─────────────────────────────────────────────────────

    public function test_sans_rh_remunerations_read_le_salaire_est_ignore_et_jamais_affiche(): void
    {
        $employe = $this->salarie('000001');
        $sansSalaire = $this->utilisateur(array_diff(self::COMPLET, ['rh-remunerations.read']), 'admin_entreprise');

        $import = $this->deposer([['matricule' => '000001', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2025', 'salaire_base' => 3000000]], $sansSalaire);
        $this->assertTrue($import->rapport['colonne_salaire_ignoree']);

        $this->confirmer($import, $sansSalaire);
        $this->assertNull(Contrat::where('employe_id', $employe->id)->firstOrFail()->salaire_base);
    }

    public function test_les_montants_sont_masques_pour_qui_n_a_pas_la_permission_meme_apres_coup(): void
    {
        $this->salarie('000001');
        $import = $this->deposer([['matricule' => '000001', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2025', 'salaire_base' => 3000000]]);

        $this->actingAs($this->user)->get(route('employes.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page->where('record.lignes.0.changements.1.apres', fn ($v) => (float) $v === 3_000_000.0));

        $this->user->revokePermissionTo('rh-remunerations.read');

        $reponse = $this->actingAs($this->user->fresh())->get(route('employes.imports.show', $import));
        $reponse->assertInertia(fn (Assert $page) => $page
            ->where('record.lignes.0.changements.1.masque', true)
            ->where('record.lignes.0.changements.1.apres', null));
        $this->assertStringNotContainsString('3000000', $reponse->getContent());
    }

    public function test_l_export_ne_contient_la_colonne_salaire_qu_avec_la_permission(): void
    {
        $employe = $this->salarie('004213', null, ['date_embauche' => '2024-03-01', 'service_rh_id' => $this->logistique->id]);
        Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2024-03-01', 'salaire_base' => 2_000_000, 'statut_contrat' => 'actif',
        ]);

        $avec = $this->lireExport($this->user);
        $this->assertContains('salaire_base', $avec[0]);
        $this->assertSame('004213', $avec[1][0], 'matricule écrit en texte');

        $sansSalaire = $this->utilisateur(array_diff(self::COMPLET, ['rh-remunerations.read']), 'admin_entreprise');
        $sans = $this->lireExport($sansSalaire);
        $this->assertNotContains('salaire_base', $sans[0]);
        $this->assertSame([], array_filter(array_merge(...$sans), fn ($c) => is_numeric($c) && (float) $c === 2_000_000.0), 'aucun montant dans l\'export');
    }

    public function test_export_puis_reimport_sans_modification_ne_change_rien(): void
    {
        $employe = $this->salarie('004213', null, ['date_embauche' => '2024-03-01', 'service_rh_id' => $this->logistique->id]);
        Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdd',
            'date_debut' => '2024-03-01', 'date_fin' => '2025-02-28', 'salaire_base' => 2_000_000, 'statut_contrat' => 'actif',
        ]);

        $reponse = $this->actingAs($this->user)->get(route('employes.export-maj'));
        $chemin = tempnam(sys_get_temp_dir(), 'export_rh').'.xlsx';
        file_put_contents($chemin, file_get_contents($reponse->baseResponse->getFile()->getPathname()));

        $this->actingAs($this->user)->post(route('employes.imports.store'), [
            'fichier' => new UploadedFile($chemin, 'export.xlsx', null, null, true),
        ]);

        $import = ImportRh::orderByDesc('id')->firstOrFail();
        $this->assertSame(0, $import->nb_lignes_erreur);
        $this->assertSame('inchange', $this->ligne($import, '004213')['statut']);
    }

    // ── Permissions et isolation ──────────────────────────────────────────────

    public function test_permissions_des_actions(): void
    {
        $this->salarie('000001');
        $import = $this->deposer([['matricule' => '000001', 'date_embauche' => '01/02/2024']]);
        $lecture = $this->utilisateur(['imports-rh.read', 'rh-employes.read'], 'admin_entreprise');

        $this->actingAs($lecture)->get(route('employes.imports.show', $import))->assertOk();
        $this->actingAs($lecture)->post(route('employes.imports.confirm', $import))->assertForbidden();
        $this->actingAs($lecture)->post(route('employes.imports.store'), ['fichier' => $this->fichier([['matricule' => '000001']])])->assertForbidden();
        $this->actingAs($lecture)->get(route('employes.export-maj'))->assertForbidden();

        $sansImport = $this->utilisateur(['rh-employes.read', 'rh-employes.update'], 'admin_entreprise');
        $this->actingAs($sansImport)->get(route('employes.imports.index'))->assertForbidden();
    }

    public function test_sans_pdg_le_dossier_est_importe_mais_aucun_contrat_n_est_cree(): void
    {
        $this->org->forceFill(['pdg_employe_id' => null])->save();
        $employe = $this->salarie('004213');
        $ligneFichier = [
            'matricule' => '004213', 'date_embauche' => '01/03/2024', 'fonction' => 'GDD',
            'contrat_type' => 'CDI', 'contrat_date_debut' => '01/03/2024', 'salaire_base' => '2 500 000',
        ];

        $import = $this->deposer([$ligneFichier]);

        // Pas d'erreur (une erreur bloquerait tout le fichier) : un avertissement explique pourquoi.
        $ligne = $this->ligne($import, '004213');
        $this->assertSame([], $ligne['erreurs']);
        $this->assertSame([ImportRhParser::AVERTISSEMENT_SANS_PDG], $ligne['avertissements']);
        $this->assertNull($ligne['mise_a_jour']['contrat']);

        $this->confirmer($import)->assertSessionHasNoErrors();
        $this->assertSame('2024-03-01', $employe->fresh()->date_embauche->toDateString());
        $this->assertSame($this->gerant->id, $employe->fresh()->fonction_rh_id);
        $this->assertSame(0, Contrat::count());
        $this->assertSame(StatutImportRh::TERMINE, $import->fresh()->statut);
        $this->assertSame(0, $import->fresh()->nb_contrats_crees);

        // Le PDG désigné, la même ligne crée le contrat.
        $this->designerPdg($this->org);
        $reimport = $this->deposer([$ligneFichier]);
        $this->assertSame([], $this->ligne($reimport, '004213')['avertissements']);
        $this->confirmer($reimport)->assertSessionHasNoErrors();
        $this->assertEquals(2_500_000, (float) Contrat::where('employe_id', $employe->id)->sole()->salaire_base);
    }

    public function test_sans_droit_de_creer_un_contrat_la_ligne_est_refusee(): void
    {
        $this->salarie('000001');
        $sansContrat = $this->utilisateur(array_diff(self::COMPLET, ['rh-contrats.create']), 'admin_entreprise');

        $import = $this->deposer([['matricule' => '000001', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/01/2025']], $sansContrat);

        $this->assertStringContainsString('Droit manquant', $this->ligne($import, '000001')['erreurs'][0]);
    }

    public function test_un_salarie_hors_des_agences_de_l_utilisateur_est_refuse(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot']);
        $this->salarie('000001', $autreSite);
        $limite = $this->utilisateur(self::COMPLET);

        $import = $this->deposer([['matricule' => '000001', 'date_embauche' => '01/02/2024']], $limite);

        $this->assertStringContainsString('ne relève pas de vos agences', $this->ligne($import, '000001')['erreurs'][0]);
    }

    public function test_isolation_entre_organisations_y_compris_pour_le_super_admin(): void
    {
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot']);
        $etranger = $this->salarie('000777', Site::where('organization_id', $autreOrg->id)->first(), [], $autreOrg);

        // Un matricule d'une autre organisation est introuvable, comme un matricule inexistant.
        $import = $this->deposer([['matricule' => '000777', 'date_embauche' => '01/02/2024']]);
        $this->assertStringContainsString('introuvable', $this->ligne($import, '000777')['erreurs'][0]);

        // L'import d'une organisation n'est ni visible ni confirmable depuis une autre.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $autreOrg->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach(Site::where('organization_id', $autreOrg->id)->first()->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($superAdmin)->get(route('employes.imports.show', $import))->assertForbidden();
        $this->actingAs($superAdmin)->post(route('employes.imports.confirm', $import))->assertForbidden();
        $this->assertNull($etranger->fresh()->date_embauche);
    }

    // ── Revue du 27/10/2026 : atomicité, cloisonnement, fichiers, journal technique ─────────

    public function test_une_erreur_au_milieu_de_l_execution_annule_toutes_les_ecritures(): void
    {
        $a = $this->salarie('000001');
        $b = $this->salarie('000002');
        // La 2e création de contrat échoue APRÈS que la 1re ligne a tout écrit (employé, affectation,
        // contrat, journal) : rien ne doit subsister.
        $this->app->instance(ContratService::class, new class extends ContratService
        {
            private int $appels = 0;

            public function creer(array $data, string $organizationId): Contrat
            {
                if (++$this->appels === 2) {
                    throw new \RuntimeException('Panne simulée');
                }

                return parent::creer($data, $organizationId);
            }
        });

        $import = $this->deposer([
            ['matricule' => '000001', 'date_embauche' => '01/02/2024', 'fonction' => 'GDD', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/02/2024'],
            ['matricule' => '000002', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/02/2024'],
        ]);
        $this->confirmer($import)->assertRedirect();

        $this->assertNull($a->fresh()->date_embauche);
        $this->assertNull($a->fresh()->fonction_rh_id);
        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, EmployeAffectation::where('motif', 'import_rh')->count());
        $this->assertSame(0, AuditLog::where('meta->import_id', $import->id)->count());
        $this->assertSame(StatutImportRh::ECHOUE, $import->fresh()->statut);
        $this->assertNull($import->fresh()->fichier_path);
        $this->assertSame([], Storage::disk('local')->allFiles('imports-rh'));
        $this->assertNull($b->fresh()->date_embauche);
    }

    public function test_une_erreur_sql_n_est_jamais_journalisee_avec_ses_valeurs(): void
    {
        Exceptions::fake();
        $this->salarie('000001');
        $this->app->instance(ContratService::class, new class extends ContratService
        {
            public function creer(array $data, string $organizationId): Contrat
            {
                throw new QueryException('mysql', 'insert into contrats (salaire_base) values (?)', [2500000], new \PDOException('panne'));
            }
        });

        $import = $this->deposer([['matricule' => '000001', 'contrat_type' => 'CDI', 'contrat_date_debut' => '01/02/2024', 'salaire_base' => 2500000]]);
        $this->confirmer($import);

        Exceptions::assertNotReported(QueryException::class);
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), $import->id) && ! str_contains($e->getMessage(), '2500000'));
        $this->assertStringNotContainsString('2500000', (string) $import->fresh()->erreur_technique);
    }

    public function test_une_erreur_imprevue_au_depot_ne_laisse_pas_le_fichier_sur_le_disque(): void
    {
        ImportRh::creating(function () {
            throw new \RuntimeException('Panne simulée');
        });

        $this->actingAs($this->user)
            ->post(route('employes.imports.store'), ['fichier' => $this->fichier([['matricule' => '000001']])])
            ->assertStatus(500);

        $this->assertSame([], Storage::disk('local')->allFiles('imports-rh'));
    }

    public function test_l_apercu_n_affiche_que_les_salaries_des_agences_du_lecteur(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot']);
        $this->salarie('000001');
        $this->salarie('000002', $autreSite);
        $import = $this->deposer([
            ['matricule' => '000001', 'date_embauche' => '01/02/2024'],
            ['matricule' => '000002', 'service' => 'Inconnu'],
        ]);
        $lecteur = $this->utilisateur(['imports-rh.read']);

        $this->actingAs($lecteur)->get(route('employes.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->has('record.lignes', 1)
                ->where('record.lignes.0.matricule', '000001')
                ->where('record.lignes_masquees', 1));

        // Le fichier d'erreurs ne contient pas non plus la ligne de l'autre agence.
        $reponse = $this->actingAs($lecteur)->get(route('employes.imports.erreurs', $import))->assertOk();
        $lignes = IOFactory::load($reponse->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
        $this->assertCount(1, $lignes, 'en-têtes seulement');
    }

    public function test_le_fichier_d_erreurs_et_l_abandon_sont_refuses_a_une_autre_organisation(): void
    {
        $this->salarie('000001');
        $import = $this->deposer([['matricule' => '000001', 'service' => 'Inconnu']]);
        $autreOrg = Organization::factory()->create();
        $site = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $autreOrg->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($superAdmin)->get(route('employes.imports.erreurs', $import))->assertForbidden();
        $this->actingAs($superAdmin)->post(route('employes.imports.abandon', $import))->assertForbidden();
        $this->actingAs($superAdmin)->get(route('employes.imports.index'))
            ->assertInertia(fn (Assert $page) => $page->has('imports', 0));
        $this->assertSame(StatutImportRh::ANALYSE, $import->fresh()->statut);
    }

    public function test_les_formules_sont_neutralisees_dans_le_fichier_d_erreurs(): void
    {
        $this->salarie('000001');
        $import = $this->deposer([['matricule' => '000001', 'service' => '=HYPERLINK("http://x","clic")']]);

        $reponse = $this->actingAs($this->user)->get(route('employes.imports.erreurs', $import))->assertOk();
        $feuille = IOFactory::load($reponse->baseResponse->getFile()->getPathname())->getActiveSheet();
        $entetes = $feuille->rangeToArray('A1:H1')[0];
        $cellule = $feuille->getCell(chr(ord('A') + array_search('service', $entetes, true)).'2');

        $this->assertNotSame('f', $cellule->getDataType(), 'jamais une formule');
        $this->assertStringStartsNotWith('=', (string) $cellule->getValue());
    }

    private function lireExport(User $user): array
    {
        $reponse = $this->actingAs($user)->get(route('employes.export-maj'))->assertOk();

        return IOFactory::load($reponse->baseResponse->getFile()->getPathname())->getSheetByName('employes')->toArray(null, true, false, false);
    }
}
