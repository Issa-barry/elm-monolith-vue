<?php

namespace Tests\Feature;

use App\Models\Employe;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Services\Rh\DepartEmployeService;
use App\Services\Rh\PdgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PDG de l'organisation (docs/rh-contrat-pdf.md, ADR 0048) : un employé RH actif désigné depuis
 * sa fiche, un seul à la fois, jamais d'une autre organisation ; la désignation tombe au départ
 * ou à la suppression du PDG.
 */
class PdgOrganisationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
    }

    private function utilisateur(array $permissions, ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function employe(string $prenom = 'Mamadou', string $nom = 'BAH', ?Organization $org = null, string $statut = 'actif'): Employe
    {
        $orgId = ($org ?? $this->org)->id;
        $personne = Personne::create([
            'organization_id' => $orgId, 'prenom' => $prenom, 'nom' => $nom,
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        return Employe::create([
            'organization_id' => $orgId, 'personne_id' => $personne->id, 'matricule' => fake()->unique()->numerify('######'),
            'type_employe' => 'interne', 'statut' => $statut, 'site_id' => $this->site->id,
        ]);
    }

    private function designer(User $user, Employe $employe)
    {
        return $this->actingAs($user)->post(route('employes.designer-pdg', $employe));
    }

    public function test_designer_un_pdg_puis_le_remplacer_garde_un_seul_pdg(): void
    {
        $user = $this->utilisateur([PdgService::PERMISSION]);
        $premier = $this->employe();
        $second = $this->employe('Aïssatou', 'CAMARA');

        $this->designer($user, $premier)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($premier->id, $this->org->fresh()->pdg_employe_id);
        $this->assertSame('Mamadou BAH', PdgService::nom(app(PdgService::class)->actuel($this->org->id)));

        $this->designer($user, $second)->assertSessionHasNoErrors();
        $this->assertSame($second->id, $this->org->fresh()->pdg_employe_id);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $second->id]);
    }

    public function test_sans_la_permission_la_designation_est_refusee_et_le_bouton_masque(): void
    {
        $employe = $this->employe();
        $user = $this->utilisateur(['rh-employes.read']);

        $this->designer($user, $employe)->assertForbidden();
        $this->assertNull($this->org->fresh()->pdg_employe_id);
        $this->actingAs($user)->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.peut_designer_pdg', false)->where('employe.est_pdg', false));
    }

    public function test_la_fiche_indique_le_pdg_et_propose_le_bouton_avec_le_droit(): void
    {
        $user = $this->utilisateur(['rh-employes.read', PdgService::PERMISSION]);
        $pdg = $this->employe();
        $autre = $this->employe('Aïssatou', 'CAMARA');
        $this->designer($user, $pdg);

        $this->actingAs($user)->get(route('employes.show', $pdg))
            ->assertInertia(fn (Assert $page) => $page->where('employe.est_pdg', true));
        $this->actingAs($user)->get(route('employes.show', $autre))
            ->assertInertia(fn (Assert $page) => $page->where('employe.est_pdg', false)
                ->where('employe.peut_designer_pdg', true)
                ->where('employe.pdg_actuel', 'Mamadou BAH'));
    }

    public function test_un_employe_d_une_autre_organisation_ne_peut_pas_etre_designe(): void
    {
        $autre = $this->employe(org: Organization::factory()->create());

        $this->designer($this->utilisateur([PdgService::PERMISSION]), $autre)->assertForbidden();

        $this->assertNull($this->org->fresh()->pdg_employe_id);
        $this->assertNull(Organization::find($autre->organization_id)->pdg_employe_id);
    }

    public function test_un_salarie_sorti_ne_peut_pas_etre_designe(): void
    {
        $sorti = $this->employe(statut: 'sorti');

        $this->designer($this->utilisateur([PdgService::PERMISSION]), $sorti)->assertSessionHasErrors('pdg');

        $this->assertNull($this->org->fresh()->pdg_employe_id);
    }

    public function test_le_depart_ou_la_suppression_du_pdg_retire_la_designation(): void
    {
        $user = $this->utilisateur([PdgService::PERMISSION, 'rh-employes.delete', 'rh-employes.read']);
        $pdg = $this->employe();
        $this->designer($user, $pdg);

        app(DepartEmployeService::class)->enregistrer($pdg, Carbon::today(), 'Démission');
        $this->assertNull($this->org->fresh()->pdg_employe_id);

        $successeur = $this->employe('Aïssatou', 'CAMARA');
        $this->designer($user, $successeur);
        $this->actingAs($user)->delete(route('employes.destroy', $successeur))->assertRedirect();
        $this->assertNull($this->org->fresh()->pdg_employe_id);
    }
}
