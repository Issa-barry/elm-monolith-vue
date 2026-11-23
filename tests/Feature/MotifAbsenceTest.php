<?php

namespace Tests\Feature;

use App\Models\MotifAbsence;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\Rh\MotifsAbsenceParDefaut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Référentiel des motifs d'absence, par organisation (docs/rh-absences.md). */
class MotifAbsenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
    }

    private function utilisateur(array $permissions = ['rh-referentiels.gerer'], ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);
        $site = Site::firstOrCreate(['organization_id' => $user->organization_id, 'nom' => 'Matoto'], ['type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_une_nouvelle_organisation_recoit_les_sept_motifs_par_defaut_dans_l_ordre(): void
    {
        $this->assertSame(
            array_values(MotifsAbsenceParDefaut::MOTIFS),
            MotifAbsence::where('organization_id', $this->org->id)->ordonnes()->pluck('libelle')->all(),
        );
        $this->assertSame(['Maladie', 'Rendez-vous médical', 'Absence autorisée', 'Absence injustifiée', 'Motif familial', 'Motif personnel', 'Autre'],
            array_values(MotifsAbsenceParDefaut::MOTIFS));
    }

    public function test_l_initialisation_est_rejouable_sans_doublon(): void
    {
        MotifAbsence::where('organization_id', $this->org->id)->where('code', 'autre')->update(['libelle' => 'Divers', 'is_active' => false]);

        $this->assertSame(0, MotifsAbsenceParDefaut::initialiserPour($this->org->id));
        $this->assertSame(7, MotifAbsence::where('organization_id', $this->org->id)->count());
        $this->assertSame('Divers', MotifAbsence::where('organization_id', $this->org->id)->where('code', 'autre')->value('libelle'));
    }

    public function test_creer_renommer_desactiver_un_motif(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->post(route('motifs-absence.store'), ['libelle' => ' Formation  externe '])->assertSessionHasNoErrors();
        $motif = MotifAbsence::where('organization_id', $this->org->id)->where('libelle', 'Formation externe')->sole();
        $this->assertSame('formation_externe', $motif->code);
        $this->assertSame(8, $motif->ordre);

        $this->actingAs($user)->put(route('motifs-absence.update', $motif), ['libelle' => 'Formation'])->assertSessionHasNoErrors();
        $this->assertSame('Formation', $motif->fresh()->libelle);
        $this->assertSame('formation_externe', $motif->fresh()->code);

        $this->actingAs($user)->patch(route('motifs-absence.toggle', $motif))->assertSessionHas('success', 'Motif désactivé.');
        $this->assertFalse($motif->fresh()->is_active);

        $this->actingAs($user)->get(route('motifs-absence.index'))
            ->assertInertia(fn (Assert $page) => $page->component('MotifsAbsence/Index')->has('motifs', 8));
    }

    public function test_un_libelle_deja_utilise_est_refuse_sans_tenir_compte_de_la_casse(): void
    {
        $this->actingAs($this->utilisateur())->post(route('motifs-absence.store'), ['libelle' => 'MALADIE'])
            ->assertSessionHasErrors(['libelle' => 'Un motif portant ce libellé existe déjà (« MALADIE »).']);
    }

    public function test_le_parametrage_exige_la_permission_et_reste_dans_l_organisation(): void
    {
        $motif = MotifAbsence::where('organization_id', $this->org->id)->where('code', 'maladie')->sole();
        $autreOrg = Organization::factory()->create();

        $this->actingAs($this->utilisateur(['rh-absences.read']))->get(route('motifs-absence.index'))->assertForbidden();
        $this->actingAs($this->utilisateur(['rh-absences.read']))->post(route('motifs-absence.store'), ['libelle' => 'X'])->assertForbidden();
        $this->actingAs($this->utilisateur(['rh-referentiels.gerer'], $autreOrg))->put(route('motifs-absence.update', $motif), ['libelle' => 'Piraté'])->assertForbidden();
        $this->actingAs($this->utilisateur(['rh-referentiels.gerer'], $autreOrg))->patch(route('motifs-absence.toggle', $motif))->assertForbidden();

        $this->assertSame('Maladie', $motif->fresh()->libelle);
        $this->assertTrue($motif->fresh()->is_active);
    }
}
