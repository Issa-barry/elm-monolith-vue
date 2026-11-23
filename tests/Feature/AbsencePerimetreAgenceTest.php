<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\Employe;
use App\Models\MotifAbsence;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Responsable d'agence (rôle `manager`) avec les droits accordés par la migration
 * 2026_11_23_100300 : il ne consulte, ne saisit, ne modifie et n'annule que les absences des
 * salariés de ses agences de rattachement — contrôlé côté serveur sur chaque point d'entrée
 * (docs/rh-absences.md § 8).
 */
class AbsencePerimetreAgenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    private Site $kaloum;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        Storage::fake('absences_rh');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['super_admin', 'admin_entreprise', 'manager', 'comptable', 'commerciale'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        // Droit existant conservé par la migration (jamais retiré).
        Permission::firstOrCreate(['name' => 'rh-employes.read', 'guard_name' => 'web']);
        Role::findByName('manager')->givePermissionTo('rh-employes.read');
        $this->executerMigrationPermissions();

        $this->org = Organization::factory()->create();
        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot']);
        $this->kaloum = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function executerMigrationPermissions(): void
    {
        (include database_path('migrations/2026_11_23_100300_backfill_permissions_absences.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Responsable d'agence : rôle manager seul, aucun droit direct. */
    private function responsable(Site ...$sites): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        foreach ($sites as $i => $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $i === 0]);
        }

        return $user;
    }

    private function employe(?Site $site): Employe
    {
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'SOW', 'prenom' => 'Awa',
            'telephone' => '+22462'.fake()->unique()->numerify('#######')]);

        return Employe::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'), 'type_employe' => 'interne', 'statut' => 'actif',
            'site_id' => $site?->id]);
    }

    private function absence(Employe $employe, string $statut = 'enregistree'): Absence
    {
        return Absence::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'motif_absence_id' => $this->motif()->id,
            'date_debut' => '2026-11-10',
            'date_fin' => '2026-11-12',
            'justificatif_path' => 'x/justificatif.pdf',
            'statut' => $statut,
        ]);
    }

    private function motif(): MotifAbsence
    {
        return MotifAbsence::where('organization_id', $this->org->id)->where('code', 'maladie')->sole();
    }

    private function saisie(Employe $employe, string $jour = '2026-11-18'): array
    {
        return ['employe_id' => $employe->id, 'motif_absence_id' => $this->motif()->id, 'date_debut' => $jour, 'date_fin' => $jour];
    }

    public function test_la_migration_n_accorde_les_droits_qu_aux_roles_prevus_et_ne_retire_rien(): void
    {
        $droits = ['rh-absences.read', 'rh-absences.create', 'rh-absences.update', 'rh-absences.annuler'];

        foreach (['super_admin', 'admin_entreprise', 'manager'] as $role) {
            $this->assertEqualsCanonicalizing($droits, array_values(array_intersect($droits, Role::findByName($role)->permissions->pluck('name')->all())), $role);
        }
        foreach (['comptable', 'commerciale'] as $role) {
            $this->assertSame([], Role::findByName($role)->permissions->pluck('name')->intersect($droits)->values()->all(), $role);
        }
        $this->assertTrue(Role::findByName('manager')->hasPermissionTo('rh-employes.read'));

        // Rejouable : aucun doublon de permission ni d'attribution.
        $this->executerMigrationPermissions();
        $this->assertSame(4, Permission::where('name', 'like', 'rh-absences.%')->count());
        $this->assertSame(5, Role::findByName('manager')->fresh()->permissions()->count());
        // Pas de droit sur le référentiel des motifs pour le responsable d'agence.
        $this->assertFalse(Role::findByName('manager')->fresh()->permissions->pluck('name')->contains('rh-referentiels.gerer'));
    }

    public function test_le_responsable_ne_voit_que_les_absences_de_son_agence(): void
    {
        $deMatoto = $this->employe($this->matoto);
        $deCba = $this->employe($this->cba);
        $sansAgence = $this->employe(null);
        $aMatoto = $this->absence($deMatoto);
        $this->absence($deCba);
        $this->absence($sansAgence);
        $responsable = $this->responsable($this->matoto);

        $this->actingAs($responsable)->get(route('absences.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('absences.data', 1)
                ->where('absences.data.0.id', $aMatoto->id)
                ->has('salaries', 1)
                ->where('salaries.0.value', $deMatoto->id)
                ->where('sites', [['id' => $this->matoto->id, 'nom' => 'Matoto']]));

        // Un filtre Agence forgé dans l'URL n'élargit pas le périmètre.
        $this->actingAs($responsable)->get(route('absences.index', ['site_ids' => [$this->cba->id]]))
            ->assertInertia(fn (Assert $page) => $page->has('absences.data', 1)->where('absences.data.0.id', $aMatoto->id));
        $this->actingAs($responsable)->get(route('absences.index', ['recherche' => 'Awa', 'statut' => 'terminee']))
            ->assertInertia(fn (Assert $page) => $page->has('absences.data', 1));
    }

    public function test_le_responsable_ne_peut_rien_faire_hors_de_son_agence(): void
    {
        $deCba = $this->employe($this->cba);
        $sansAgence = $this->employe(null);
        $enregistree = $this->absence($deCba);
        $annulee = $this->absence($deCba, 'annulee');
        $responsable = $this->responsable($this->matoto);
        $modification = ['motif_absence_id' => $this->motif()->id, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-13'];

        foreach ([$enregistree, $annulee] as $absence) {
            $this->actingAs($responsable)->getJson(route('absences.show', $absence))->assertForbidden();
            $this->actingAs($responsable)->getJson(route('absences.historique', $absence))->assertForbidden();
            $this->actingAs($responsable)->get(route('absences.justificatif', $absence))->assertForbidden();
            // 403 même sur une absence annulée : le statut n'est jamais révélé hors périmètre.
            $this->actingAs($responsable)->put(route('absences.update', $absence), $modification)->assertForbidden();
            $this->actingAs($responsable)->patch(route('absences.annuler', $absence))->assertForbidden();
        }
        $this->actingAs($responsable)->post(route('absences.store'), $this->saisie($deCba))->assertSessionHasErrors(['employe_id']);
        $this->actingAs($responsable)->post(route('absences.store'), $this->saisie($sansAgence))->assertSessionHasErrors(['employe_id']);
        $this->actingAs($responsable)->get(route('employes.show', $deCba))->assertForbidden();
        $this->actingAs($responsable)->get(route('motifs-absence.index'))->assertForbidden();

        $this->assertSame(2, Absence::count());
        $this->assertSame('enregistree', $enregistree->fresh()->statut->value);
        $this->assertSame('2026-11-12', $enregistree->fresh()->date_fin->toDateString());
    }

    public function test_le_responsable_gere_entierement_les_absences_de_son_agence(): void
    {
        $deMatoto = $this->employe($this->matoto);
        $responsable = $this->responsable($this->matoto);

        $this->actingAs($responsable)->post(route('absences.store'), $this->saisie($deMatoto))->assertSessionHasNoErrors();
        $absence = Absence::sole();
        $this->actingAs($responsable)->getJson(route('absences.show', $absence))->assertOk()->assertJsonPath('peut_annuler', true);
        $this->actingAs($responsable)->put(route('absences.update', $absence), [
            'motif_absence_id' => $this->motif()->id, 'date_debut' => '2026-11-18', 'date_fin' => '2026-11-19',
        ])->assertSessionHasNoErrors();
        $this->actingAs($responsable)->patch(route('absences.annuler', $absence))->assertSessionHasNoErrors();
        $this->actingAs($responsable)->get(route('employes.show', $deMatoto))
            ->assertInertia(fn (Assert $page) => $page->has('absences', 1)->where('absences_options.peut_saisir', true));

        $this->assertSame('annulee', $absence->fresh()->statut->value);
    }

    public function test_un_responsable_de_deux_agences_les_gere_toutes_les_deux_seulement(): void
    {
        $deMatoto = $this->absence($this->employe($this->matoto));
        $deCba = $this->absence($this->employe($this->cba));
        $deKaloum = $this->absence($this->employe($this->kaloum));
        $responsable = $this->responsable($this->matoto, $this->cba);

        $this->actingAs($responsable)->get(route('absences.index'))
            ->assertInertia(fn (Assert $page) => $page->has('absences.data', 2));
        $this->actingAs($responsable)->patch(route('absences.annuler', $deCba))->assertSessionHasNoErrors();
        $this->actingAs($responsable)->patch(route('absences.annuler', $deMatoto))->assertSessionHasNoErrors();
        $this->actingAs($responsable)->patch(route('absences.annuler', $deKaloum))->assertForbidden();

        $this->assertSame('enregistree', $deKaloum->fresh()->statut->value);
    }
}
