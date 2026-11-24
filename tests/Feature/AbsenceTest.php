<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\Absence;
use App\Models\AuditLog;
use App\Models\Employe;
use App\Models\MotifAbsence;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Absences des salariés (docs/rh-absences.md, ADR 0070) : saisie, durée en jours calendaires
 * inclus, chevauchements et doublons, modification, annulation logique, justificatif privé,
 * permissions et périmètre d'agence (PerimetreEmploye).
 */
class AbsenceTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['rh-absences.read', 'rh-absences.create', 'rh-absences.update', 'rh-absences.annuler', 'rh-employes.read'];

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('absences_rh');

        $this->org = Organization::factory()->create();
        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Fabriques ─────────────────────────────────────────────────────────────────────────

    private function utilisateur(?Site $site, array $permissions = self::DROITS, string $role = 'manager', ?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        if ($site !== null) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        }

        return $user;
    }

    private function rh(): User
    {
        // Administrateur : agit sur toutes les agences, quelle que soit son agence de rattachement.
        return $this->utilisateur($this->cba, self::DROITS, 'admin_entreprise');
    }

    private function employe(?Site $site, array $attributs = []): Employe
    {
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'DIALLO', 'prenom' => 'Alpha',
            'telephone' => '+22462'.fake()->unique()->numerify('#######')]);

        return Employe::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'), 'type_employe' => 'interne', 'statut' => 'actif',
            'site_id' => $site?->id, ...$attributs]);
    }

    private function motif(string $code = 'maladie', ?Organization $org = null): MotifAbsence
    {
        return MotifAbsence::where('organization_id', ($org ?? $this->org)->id)->where('code', $code)->firstOrFail();
    }

    private function saisie(Employe $employe, array $champs = []): array
    {
        return [
            'employe_id' => $employe->id,
            'motif_absence_id' => $this->motif()->id,
            'date_debut' => '2026-11-10',
            'date_fin' => '2026-11-12',
            ...$champs,
        ];
    }

    private function absence(Employe $employe, string $debut, string $fin, ?MotifAbsence $motif = null, string $statut = 'enregistree'): Absence
    {
        return Absence::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'motif_absence_id' => ($motif ?? $this->motif())->id,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'statut' => $statut,
        ]);
    }

    // ── Saisie ────────────────────────────────────────────────────────────────────────────

    public function test_rh_enregistre_une_absence_avec_sa_duree_et_son_journal(): void
    {
        $employe = $this->employe($this->matoto);
        $rh = $this->rh();

        $this->actingAs($rh)
            ->post(route('absences.store'), $this->saisie($employe, ['commentaire' => '  Certificat à venir  ']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Absence enregistrée (3 jours).');

        $absence = Absence::sole();
        $this->assertSame('enregistree', $absence->statut->value);
        $this->assertSame(3, $absence->duree());
        $this->assertSame('Certificat à venir', $absence->commentaire);
        $this->assertSame($rh->id, $absence->cree_par);
        $this->assertNull($absence->justificatif_path);

        $log = AuditLog::where('auditable_id', $absence->id)->sole();
        $this->assertSame(AuditEvent::CREATED->value, $log->event_code);
        $this->assertSame('rh_absences', $log->meta['module']);
        $this->assertSame(3, $log->new_values['duree_jours']);
        $this->assertSame('Maladie', $log->new_values['motif']);
    }

    public function test_la_duree_compte_les_jours_calendaires_bornes_incluses(): void
    {
        $this->assertSame(3, Absence::dureeEnJours(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-12')));
        $this->assertSame(1, Absence::dureeEnJours(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-10')));
        // Week-end et changement de mois compris : ni jours ouvrés ni jours ouvrables.
        $this->assertSame(9, Absence::dureeEnJours(CarbonImmutable::parse('2026-11-27'), CarbonImmutable::parse('2026-12-05')));
    }

    public function test_une_duree_envoyee_par_le_navigateur_est_ignoree(): void
    {
        $employe = $this->employe($this->matoto);

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe, ['duree_jours' => 99]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Absence::sole()->duree());
    }

    public function test_le_motif_et_les_dates_sont_obligatoires(): void
    {
        $employe = $this->employe($this->matoto);

        $this->actingAs($this->rh())
            ->post(route('absences.store'), ['employe_id' => $employe->id])
            ->assertSessionHasErrors([
                'motif_absence_id' => 'Le motif est obligatoire.',
                'date_debut' => 'La date de début est obligatoire.',
                'date_fin' => 'La date de fin est obligatoire.',
            ]);

        $this->assertSame(0, Absence::count());
    }

    public function test_la_date_de_fin_ne_peut_pas_preceder_la_date_de_debut(): void
    {
        $employe = $this->employe($this->matoto);

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe, ['date_debut' => '2026-11-12', 'date_fin' => '2026-11-10']))
            ->assertSessionHasErrors(['date_fin' => 'La date de fin doit être postérieure ou égale à la date de début.']);

        $this->assertSame(0, Absence::count());
    }

    public function test_un_motif_desactive_ou_d_une_autre_organisation_est_refuse(): void
    {
        $employe = $this->employe($this->matoto);
        $this->motif('autre')->update(['is_active' => false]);
        $autreOrg = Organization::factory()->create();

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe, ['motif_absence_id' => $this->motif('autre')->id]))
            ->assertSessionHasErrors(['motif_absence_id' => 'Le motif « Autre » est désactivé : choisissez-en un autre.']);
        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe, ['motif_absence_id' => $this->motif('maladie', $autreOrg)->id]))
            ->assertSessionHasErrors(['motif_absence_id' => 'Motif introuvable.']);

        $this->assertSame(0, Absence::count());
    }

    public function test_le_salarie_doit_etre_en_poste_sur_la_periode(): void
    {
        $nouveau = $this->employe($this->matoto, ['date_embauche' => '2026-11-11']);
        $sorti = $this->employe($this->matoto, ['statut' => 'sorti', 'date_embauche' => '2025-01-01', 'date_sortie' => '2026-11-11']);
        $rh = $this->rh();

        $this->actingAs($rh)->post(route('absences.store'), $this->saisie($nouveau))
            ->assertSessionHasErrors(['date_debut' => 'Le salarié n\'est embauché qu\'à partir du 11/11/2026.']);
        $this->actingAs($rh)->post(route('absences.store'), $this->saisie($sorti))
            ->assertSessionHasErrors(['date_fin']);

        // Une absence passée, pendant l'emploi, reste saisissable pour un salarié sorti.
        $this->actingAs($rh)->post(route('absences.store'), $this->saisie($sorti, ['date_debut' => '2026-11-09', 'date_fin' => '2026-11-11']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Absence::count());
    }

    public function test_un_salarie_d_une_autre_organisation_est_introuvable(): void
    {
        $autreOrg = Organization::factory()->create();
        $personne = Personne::create(['organization_id' => $autreOrg->id, 'nom' => 'BAH', 'prenom' => 'Awa', 'telephone' => '+224620000001']);
        $etranger = Employe::create(['organization_id' => $autreOrg->id, 'personne_id' => $personne->id, 'matricule' => '999999',
            'type_employe' => 'interne', 'statut' => 'actif']);

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($etranger))
            ->assertSessionHasErrors(['employe_id' => 'Salarié introuvable.']);
    }

    // ── Justificatif ──────────────────────────────────────────────────────────────────────

    public function test_le_justificatif_est_facultatif_stocke_en_prive_et_consultable(): void
    {
        $employe = $this->employe($this->matoto);
        $rh = $this->rh();

        $this->actingAs($rh)
            ->post(route('absences.store'), $this->saisie($employe, [
                'justificatif' => UploadedFile::fake()->create('certificat.pdf', 120, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $absence = Absence::sole();
        $this->assertSame('certificat.pdf', $absence->justificatif_nom_original);
        $this->assertStringStartsWith("{$this->org->id}/{$employe->id}/{$absence->id}/justificatif-", $absence->justificatif_path);
        Storage::disk('absences_rh')->assertExists($absence->justificatif_path);

        $this->actingAs($rh)->get(route('absences.justificatif', $absence))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_un_justificatif_qui_n_est_ni_pdf_ni_image_est_refuse(): void
    {
        $employe = $this->employe($this->matoto);

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe, [
                'justificatif' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'),
            ]))
            ->assertSessionHasErrors(['justificatif' => 'Le justificatif doit être un PDF ou une image (JPG, PNG, WEBP).']);

        $this->assertSame(0, Absence::count());
        $this->assertSame([], Storage::disk('absences_rh')->allFiles());
    }

    public function test_remplacer_puis_retirer_le_justificatif_supprime_l_ancien_fichier(): void
    {
        $employe = $this->employe($this->matoto);
        $rh = $this->rh();
        $this->actingAs($rh)->post(route('absences.store'), $this->saisie($employe, [
            'justificatif' => UploadedFile::fake()->image('photo.jpg'),
        ]));
        $absence = Absence::sole();
        $ancien = $absence->justificatif_path;

        $this->actingAs($rh)->put(route('absences.update', $absence), [
            'motif_absence_id' => $absence->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-12',
            'justificatif' => UploadedFile::fake()->create('certificat.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $absence->refresh();
        Storage::disk('absences_rh')->assertMissing($ancien);
        Storage::disk('absences_rh')->assertExists($absence->justificatif_path);

        $this->actingAs($rh)->put(route('absences.update', $absence), [
            'motif_absence_id' => $absence->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-12',
            'retirer_justificatif' => true,
        ])->assertSessionHasNoErrors();
        $this->assertNull($absence->fresh()->justificatif_path);
        $this->assertSame([], Storage::disk('absences_rh')->allFiles());
        $this->actingAs($rh)->get(route('absences.justificatif', $absence))->assertNotFound();
    }

    // ── Chevauchements et doublons ────────────────────────────────────────────────────────

    public function test_un_chevauchement_est_refuse_avec_l_absence_existante(): void
    {
        $employe = $this->employe($this->matoto);
        $existante = $this->absence($employe, '2026-11-10', '2026-11-12');

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe, [
                'motif_absence_id' => $this->motif('motif_familial')->id, 'date_debut' => '2026-11-12', 'date_fin' => '2026-11-14',
            ]))
            ->assertSessionHasErrors([
                'chevauchement' => 'Ce salarié a déjà une absence sur cette période (Maladie, du 10/11/2026 au 12/11/2026). Modifiez l\'absence existante ou choisissez d\'autres dates.',
                'absence_existante' => $existante->id,
            ]);

        $this->assertSame(1, Absence::count());
    }

    public function test_un_doublon_est_refuse(): void
    {
        $employe = $this->employe($this->matoto);
        $existante = $this->absence($employe, '2026-11-10', '2026-11-12');

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe))
            ->assertSessionHasErrors([
                'chevauchement' => 'Cette absence est déjà enregistrée (Maladie, du 10/11/2026 au 12/11/2026) : doublon refusé.',
                'absence_existante' => $existante->id,
            ]);

        $this->assertSame(1, Absence::count());
    }

    public function test_une_absence_annulee_ou_d_un_autre_salarie_ne_bloque_pas(): void
    {
        $employe = $this->employe($this->matoto);
        $collegue = $this->employe($this->matoto);
        $this->absence($employe, '2026-11-10', '2026-11-12', statut: 'annulee');
        $this->absence($collegue, '2026-11-10', '2026-11-12');

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Absence::count());
    }

    public function test_des_absences_contigues_sans_jour_commun_sont_acceptees(): void
    {
        $employe = $this->employe($this->matoto);
        $this->absence($employe, '2026-11-10', '2026-11-12');

        $this->actingAs($this->rh())
            ->post(route('absences.store'), $this->saisie($employe, ['date_debut' => '2026-11-13', 'date_fin' => '2026-11-13']))
            ->assertSessionHasNoErrors();
    }

    // ── Modification et annulation ────────────────────────────────────────────────────────

    public function test_modifier_une_absence_journalise_les_changements(): void
    {
        $employe = $this->employe($this->matoto);
        $absence = $this->absence($employe, '2026-11-10', '2026-11-12');
        $rh = $this->rh();

        // Elle ne se chevauche pas elle-même.
        $this->actingAs($rh)->put(route('absences.update', $absence), [
            'motif_absence_id' => $this->motif('rendez_vous_medical')->id,
            'date_debut' => '2026-11-11',
            'date_fin' => '2026-11-12',
            'commentaire' => 'Matin seulement',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Absence modifiée.');

        $absence->refresh();
        $this->assertSame('2026-11-11', $absence->date_debut->toDateString());
        $this->assertSame(2, $absence->duree());
        $this->assertSame($rh->id, $absence->modifie_par);

        $log = AuditLog::where('auditable_id', $absence->id)->where('event_code', AuditEvent::UPDATED->value)->sole();
        $this->assertSame(['motif' => 'Maladie', 'date_debut' => '10/11/2026', 'duree_jours' => 3, 'commentaire' => null], $log->old_values);
        $this->assertSame(['motif' => 'Rendez-vous médical', 'date_debut' => '11/11/2026', 'duree_jours' => 2, 'commentaire' => 'Matin seulement'], $log->new_values);
    }

    public function test_une_modification_qui_chevauche_une_autre_absence_est_refusee(): void
    {
        $employe = $this->employe($this->matoto);
        $absence = $this->absence($employe, '2026-11-10', '2026-11-12');
        $autre = $this->absence($employe, '2026-11-15', '2026-11-16');

        $this->actingAs($this->rh())->put(route('absences.update', $absence), [
            'motif_absence_id' => $absence->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-15',
        ])->assertSessionHasErrors(['absence_existante' => $autre->id]);

        $this->assertSame('2026-11-12', $absence->fresh()->date_fin->toDateString());
    }

    public function test_le_salarie_d_une_absence_ne_se_modifie_pas(): void
    {
        $employe = $this->employe($this->matoto);
        $absence = $this->absence($employe, '2026-11-10', '2026-11-12');

        $this->actingAs($this->rh())->put(route('absences.update', $absence), [
            'employe_id' => $this->employe($this->matoto)->id,
            'motif_absence_id' => $absence->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-12',
        ])->assertSessionHasErrors(['employe_id']);

        $this->assertSame($employe->id, $absence->fresh()->employe_id);
    }

    public function test_un_motif_desactive_depuis_reste_accepte_s_il_est_deja_celui_de_l_absence(): void
    {
        $employe = $this->employe($this->matoto);
        $absence = $this->absence($employe, '2026-11-10', '2026-11-12', $this->motif('autre'));
        $this->motif('autre')->update(['is_active' => false]);

        $this->actingAs($this->rh())->put(route('absences.update', $absence), [
            'motif_absence_id' => $absence->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-13',
        ])->assertSessionHasNoErrors();
    }

    public function test_annuler_conserve_l_absence_sans_suppression_physique(): void
    {
        $employe = $this->employe($this->matoto);
        $absence = $this->absence($employe, '2026-11-10', '2026-11-12');
        $rh = $this->rh();

        $this->actingAs($rh)->patch(route('absences.annuler', $absence), ['motif_annulation' => 'Saisie en double'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $absence->refresh();
        $this->assertSame('annulee', $absence->statut->value);
        $this->assertSame($rh->id, $absence->annule_par);
        $this->assertSame('Saisie en double', $absence->motif_annulation);
        $this->assertNotNull($absence->annule_le);
        $this->assertSame(1, Absence::count());
        $this->assertDatabaseHas('absences', ['id' => $absence->id, 'statut' => 'annulee']);
        $this->assertTrue(AuditLog::where('auditable_id', $absence->id)->where('event_code', AuditEvent::CANCELLED->value)->exists());

        // Ni modifiable ni annulable de nouveau.
        $this->actingAs($rh)->put(route('absences.update', $absence), [
            'motif_absence_id' => $absence->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-12',
        ])->assertSessionHasErrors(['absence']);
        $this->actingAs($rh)->patch(route('absences.annuler', $absence))->assertSessionHasErrors(['absence']);
    }

    // ── Permissions et périmètre d'agence ─────────────────────────────────────────────────

    public function test_chaque_action_exige_sa_permission(): void
    {
        $employe = $this->employe($this->matoto);
        $absence = $this->absence($employe, '2026-11-10', '2026-11-12');
        $lecteur = $this->utilisateur($this->matoto, ['rh-absences.read']);
        $sansDroit = $this->utilisateur($this->matoto, ['rh-employes.read']);

        $this->actingAs($sansDroit)->get(route('absences.index'))->assertForbidden();
        $this->actingAs($sansDroit)->getJson(route('absences.show', $absence))->assertForbidden();
        $this->actingAs($lecteur)->get(route('absences.index'))->assertOk();
        $this->actingAs($lecteur)->post(route('absences.store'), $this->saisie($employe, ['date_debut' => '2026-11-20', 'date_fin' => '2026-11-20']))->assertForbidden();
        $this->actingAs($lecteur)->put(route('absences.update', $absence), [
            'motif_absence_id' => $absence->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-13',
        ])->assertForbidden();
        $this->actingAs($lecteur)->patch(route('absences.annuler', $absence))->assertForbidden();

        $this->assertSame(1, Absence::count());
        $this->assertSame('enregistree', $absence->fresh()->statut->value);
    }

    public function test_le_responsable_d_agence_gere_les_absences_de_son_agence_seulement(): void
    {
        $deMatoto = $this->employe($this->matoto);
        $deCba = $this->employe($this->cba);
        $absenceCba = $this->absence($deCba, '2026-11-10', '2026-11-12');
        $responsableMatoto = $this->utilisateur($this->matoto);

        // Son agence : saisie, lecture.
        $this->actingAs($responsableMatoto)->post(route('absences.store'), $this->saisie($deMatoto))->assertSessionHasNoErrors();
        $this->actingAs($responsableMatoto)->get(route('absences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Absences/Index')
                ->has('absences.data', 1)
                ->where('absences.data.0.employe_id', $deMatoto->id)
                ->where('absences.data.0.peut_modifier', true)
                ->where('absences.data.0.peut_annuler', true)
                ->has('salaries', 1)
                ->where('salaries.0.value', $deMatoto->id));

        // Autre agence : ni saisie, ni lecture, ni modification, ni annulation, ni justificatif.
        $this->actingAs($responsableMatoto)->post(route('absences.store'), $this->saisie($deCba, ['date_debut' => '2026-11-20', 'date_fin' => '2026-11-20']))
            ->assertSessionHasErrors(['employe_id']);
        $this->actingAs($responsableMatoto)->getJson(route('absences.show', $absenceCba))->assertForbidden();
        $this->actingAs($responsableMatoto)->getJson(route('absences.historique', $absenceCba))->assertForbidden();
        $this->actingAs($responsableMatoto)->get(route('absences.justificatif', $absenceCba))->assertForbidden();
        $this->actingAs($responsableMatoto)->put(route('absences.update', $absenceCba), [
            'motif_absence_id' => $absenceCba->motif_absence_id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-13',
        ])->assertForbidden();
        $this->actingAs($responsableMatoto)->patch(route('absences.annuler', $absenceCba))->assertForbidden();

        $this->assertSame('enregistree', $absenceCba->fresh()->statut->value);
        $this->assertSame('2026-11-12', $absenceCba->fresh()->date_fin->toDateString());
    }

    public function test_la_vision_de_toutes_les_agences_ouvre_la_lecture_pas_l_ecriture(): void
    {
        $deCba = $this->employe($this->cba);
        $absence = $this->absence($deCba, '2026-11-10', '2026-11-12');
        $lecteur = $this->utilisateur($this->matoto, [...self::DROITS, 'sites.lecture_toutes_agences']);

        $this->actingAs($lecteur)->get(route('absences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('absences.data', 1)
                ->where('absences.data.0.peut_modifier', false)
                ->where('absences.data.0.peut_annuler', false));
        $this->actingAs($lecteur)->getJson(route('absences.show', $absence))->assertOk()->assertJsonPath('salarie', 'Alpha DIALLO');
        $this->actingAs($lecteur)->patch(route('absences.annuler', $absence))->assertForbidden();
    }

    public function test_rh_gere_toutes_les_agences(): void
    {
        $deCba = $this->employe($this->cba);
        $absence = $this->absence($deCba, '2026-11-10', '2026-11-12');

        $this->actingAs($this->rh())->get(route('absences.index'))
            ->assertInertia(fn (Assert $page) => $page->where('absences.data.0.peut_annuler', true));
        $this->actingAs($this->rh())->patch(route('absences.annuler', $absence))->assertSessionHasNoErrors();
    }

    public function test_aucun_acces_aux_absences_d_une_autre_organisation(): void
    {
        $absence = $this->absence($this->employe($this->matoto), '2026-11-10', '2026-11-12');
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        // Super administrateur : le Gate::before passe les policies, l'organisation est revérifiée.
        $etranger = $this->utilisateur(null, self::DROITS, 'super_admin', $autreOrg);

        $this->actingAs($etranger)->getJson(route('absences.show', $absence))->assertForbidden();
        $this->actingAs($etranger)->get(route('absences.justificatif', $absence))->assertForbidden();
        $this->actingAs($etranger)->patch(route('absences.annuler', $absence))->assertForbidden();
        $this->actingAs($etranger)->get(route('absences.index'))
            ->assertInertia(fn (Assert $page) => $page->has('absences.data', 0));
    }

    // ── Liste et filtres ──────────────────────────────────────────────────────────────────

    public function test_la_liste_filtre_par_recherche_motif_statut_auteur_et_periode(): void
    {
        $alpha = $this->employe($this->matoto);
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'CAMARA', 'prenom' => 'Fanta', 'telephone' => '+224620000002']);
        $fanta = Employe::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'matricule' => '424242',
            'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => $this->cba->id]);
        $rh = $this->rh();
        $octobre = $this->absence($alpha, '2026-10-01', '2026-10-03');
        $enCours = $this->absence($fanta, '2026-11-18', '2026-11-25', $this->motif('motif_familial'));
        $enCours->update(['cree_par' => $rh->id]);
        $annulee = $this->absence($alpha, '2026-11-02', '2026-11-02', statut: 'annulee');

        $ids = fn (array $params) => collect($this->actingAs($rh)->get(route('absences.index', $params))
            ->assertOk()->viewData('page')['props']['absences']['data'])->pluck('id')->all();

        // Par défaut : annulées exclues, plus récentes d'abord.
        $this->assertSame([$enCours->id, $octobre->id], $ids([]));
        $this->assertSame([$enCours->id], $ids(['recherche' => 'fanta']));
        $this->assertSame([$enCours->id], $ids(['recherche' => '424242']));
        $this->assertSame([$enCours->id], $ids(['motif_id' => $this->motif('motif_familial')->id]));
        $this->assertSame([$enCours->id], $ids(['auteur_id' => $rh->id]));
        $this->assertSame([$enCours->id], $ids(['statut' => 'en_cours']));
        $this->assertSame([$octobre->id], $ids(['statut' => 'terminee']));
        $this->assertSame([$annulee->id], $ids(['statut' => 'annulee']));
        $this->assertSame([$enCours->id], $ids(['site_ids' => [$this->cba->id]]));
        // Période : une absence qui déborde de la période y figure (du 18 au 25 touche « Cette semaine »).
        $this->assertSame([$enCours->id], $ids(['periode' => 'cette_semaine']));
        $this->assertSame([$octobre->id], $ids(['date_debut' => '2026-10-03', 'date_fin' => '2026-10-10']));

        $this->actingAs($rh)->get(route('absences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auteurs', [['value' => $rh->id, 'label' => $rh->name]])
                ->where('absences.data.0.duree_jours', 8)
                ->where('absences.data.0.etat', 'en_cours')
                ->where('absences.data.0.agence', 'CBA')
                ->where('absences.data.0.matricule', '424242'));
    }

    // ── Fiche salarié ─────────────────────────────────────────────────────────────────────

    public function test_la_fiche_salarie_affiche_ses_absences_annulees_comprises(): void
    {
        $employe = $this->employe($this->matoto);
        $this->absence($employe, '2026-11-10', '2026-11-12');
        $this->absence($employe, '2026-11-02', '2026-11-02', statut: 'annulee');
        $this->absence($this->employe($this->matoto), '2026-11-10', '2026-11-12');

        $this->actingAs($this->utilisateur($this->matoto))->get(route('employes.show', $employe))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('absences', 2)
                ->where('absences.0.date_debut_label', '10/11/2026')
                ->where('absences.0.duree_jours', 3)
                ->where('absences.0.etat', 'terminee')
                ->where('absences.1.etat', 'annulee')
                ->where('absences_options.peut_saisir', true)
                ->has('absences_options.motifs', 7));
    }

    public function test_la_fiche_salarie_n_envoie_pas_les_absences_sans_permission(): void
    {
        $employe = $this->employe($this->matoto);
        $this->absence($employe, '2026-11-10', '2026-11-12');

        $this->actingAs($this->utilisateur($this->matoto, ['rh-employes.read']))->get(route('employes.show', $employe))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('absences', null)->where('absences_options', null));
    }

    /**
     * Motif médical et commentaire confidentiels : l'historique comptable (`comptabilite.read`) ne
     * montre jamais les absences, ni en liste, ni par entité, ni dans ses filtres ; le journal est
     * conservé et reste lisible dans le module RH.
     */
    public function test_l_historique_comptable_n_expose_pas_les_absences(): void
    {
        $employe = $this->employe($this->matoto);
        $rh = $this->rh();
        $this->actingAs($rh)->post(route('absences.store'), $this->saisie($employe, ['commentaire' => 'Hospitalisation']));
        $absence = Absence::sole();
        $this->actingAs($rh)->patch(route('absences.annuler', $absence));
        // Un journal comptable ordinaire, qui doit rester visible.
        AuditLog::create([
            'organization_id' => $this->org->id, 'auditable_type' => 'App\\Models\\Depense', 'auditable_id' => 'dep-1',
            'event_code' => AuditEvent::VALIDATED->value, 'event_label' => AuditEvent::VALIDATED->label(),
            'actor_id' => $rh->id, 'actor_name_snapshot' => $rh->name,
            'meta' => ['module' => 'depenses', 'description' => 'Dépense validée'], 'created_at' => now(),
        ]);
        $comptable = $this->utilisateur($this->matoto, ['comptabilite.read'], 'comptable');

        $this->actingAs($comptable)->get(route('comptabilite.historique.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.module', 'depenses')
                ->where('modules', fn ($modules) => ! collect($modules)->contains('value', 'rh_absences')));
        $this->actingAs($comptable)->get(route('comptabilite.historique.index', ['module' => 'rh_absences']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
        // La recherche texte de l'historique utilise JSON_UNQUOTE (MySQL / MariaDB), absent de SQLite.
        if (DB::getDriverName() !== 'sqlite') {
            $this->actingAs($comptable)->get(route('comptabilite.historique.index', ['search' => 'Absence']))
                ->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
            $this->actingAs($comptable)->get(route('comptabilite.historique.index', ['search' => 'Dépense']))
                ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        }
        $this->actingAs($comptable)->getJson(route('comptabilite.historique.entite', [
            'auditable_type' => $absence->getMorphClass(), 'auditable_id' => $absence->id,
        ]))->assertForbidden();

        // Rien n'est supprimé : le journal complet reste dans le module RH.
        $this->assertSame(2, AuditLog::where('auditable_id', $absence->id)->count());
        $this->actingAs($rh)->getJson(route('absences.historique', $absence))->assertOk()->assertJsonCount(2);
    }

    public function test_l_historique_d_une_absence_est_consultable(): void
    {
        $employe = $this->employe($this->matoto);
        $rh = $this->rh();
        $this->actingAs($rh)->post(route('absences.store'), $this->saisie($employe));
        $absence = Absence::sole();
        $this->actingAs($rh)->patch(route('absences.annuler', $absence));

        $this->actingAs($rh)->getJson(route('absences.historique', $absence))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.event_code', 'cancelled')
            ->assertJsonPath('1.event_code', 'created')
            ->assertJsonPath('1.module_label', 'Absences');

        $this->actingAs($rh)->getJson(route('absences.show', $absence))
            ->assertOk()
            ->assertJsonPath('etat', 'annulee')
            ->assertJsonPath('annule_par', $rh->name)
            ->assertJsonPath('peut_modifier', false);
    }
}
