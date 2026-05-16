<?php

namespace Tests\Feature;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Enums\TypeEmploye;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org  = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
        $this->user = $this->makeUser(['rh-employes.read', 'rh-employes.create', 'rh-employes.update', 'rh-employes.delete']);
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

    private function makeEmploye(array $overrides = []): Employe
    {
        return Employe::create(array_merge([
            'organization_id' => $this->org->id,
            'matricule'       => '000001',
            'nom'             => 'DIALLO',
            'prenom'          => 'Mamadou',
            'type_employe'    => TypeEmploye::INTERNE->value,
            'statut'          => StatutEmploye::ACTIF->value,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('employes.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated(): void
    {
        $this->get(route('employes.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        $this->actingAs($user)
            ->get(route('employes.index'))
            ->assertStatus(403);
    }

    // ── Filtres (les 3 obligatoires) ──────────────────────────────────────────

    public function test_index_filter_by_statut(): void
    {
        $this->makeEmploye(['statut' => StatutEmploye::ACTIF->value, 'matricule' => '000010']);
        $this->makeEmploye(['statut' => StatutEmploye::SORTI->value, 'matricule' => '000011']);

        $response = $this->actingAs($this->user)
            ->get(route('employes.index', ['statut' => 'actif']));

        $response->assertStatus(200);
        $employes = $response->original->getData()['page']['props']['employes'];
        $this->assertCount(1, $employes);
        $this->assertSame('actif', $employes[0]['statut']);
    }

    public function test_index_filter_by_type_employe(): void
    {
        $this->makeEmploye(['type_employe' => TypeEmploye::INTERNE->value, 'matricule' => '000020']);
        $this->makeEmploye(['type_employe' => TypeEmploye::EXTERNE->value, 'matricule' => '000021']);

        $response = $this->actingAs($this->user)
            ->get(route('employes.index', ['type_employe' => 'externe']));

        $employes = $response->original->getData()['page']['props']['employes'];
        $this->assertCount(1, $employes);
        $this->assertSame('externe', $employes[0]['type_employe']);
    }

    public function test_index_filter_by_type_contrat(): void
    {
        $e1 = $this->makeEmploye(['matricule' => '000030']);
        $e2 = $this->makeEmploye(['matricule' => '000031']);

        Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id'      => $e1->id,
            'type_contrat'    => TypeContrat::CDI->value,
            'date_debut'      => now(),
            'statut_contrat'  => StatutContrat::ACTIF->value,
        ]);

        Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id'      => $e2->id,
            'type_contrat'    => TypeContrat::CDD->value,
            'date_debut'      => now(),
            'date_fin'        => now()->addYear(),
            'statut_contrat'  => StatutContrat::ACTIF->value,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('employes.index', ['type_contrat' => 'cdi']));

        $employes = $response->original->getData()['page']['props']['employes'];
        $this->assertCount(1, $employes);
        $this->assertSame($e1->id, $employes[0]['id']);
    }

    public function test_index_filters_are_combinable(): void
    {
        // Interne actif CDI
        $e1 = $this->makeEmploye(['matricule' => '000040', 'type_employe' => 'interne', 'statut' => 'actif']);
        Contrat::create(['organization_id' => $this->org->id, 'employe_id' => $e1->id, 'type_contrat' => 'cdi', 'date_debut' => now(), 'statut_contrat' => 'actif']);

        // Externe actif CDI → ne doit pas apparaître si on filtre interne
        $e2 = $this->makeEmploye(['matricule' => '000041', 'type_employe' => 'externe', 'statut' => 'actif']);
        Contrat::create(['organization_id' => $this->org->id, 'employe_id' => $e2->id, 'type_contrat' => 'cdi', 'date_debut' => now(), 'statut_contrat' => 'actif']);

        // Interne sorti CDI → ne doit pas apparaître si on filtre actif
        $e3 = $this->makeEmploye(['matricule' => '000042', 'type_employe' => 'interne', 'statut' => 'sorti']);
        Contrat::create(['organization_id' => $this->org->id, 'employe_id' => $e3->id, 'type_contrat' => 'cdi', 'date_debut' => now(), 'statut_contrat' => 'actif']);

        $response = $this->actingAs($this->user)->get(route('employes.index', [
            'statut'       => 'actif',
            'type_employe' => 'interne',
            'type_contrat' => 'cdi',
        ]));

        $employes = $response->original->getData()['page']['props']['employes'];
        $this->assertCount(1, $employes);
        $this->assertSame($e1->id, $employes[0]['id']);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_employe_with_matricule(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('employes.store'), [
                'nom'          => 'BARRY',
                'prenom'       => 'Alpha',
                'type_employe' => 'interne',
                'statut'       => 'actif',
            ]);

        $employe = Employe::where('organization_id', $this->org->id)->firstOrFail();
        $response->assertRedirect(route('employes.edit', $employe));

        $this->assertNotNull($employe->matricule);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $employe->matricule);
    }

    public function test_store_fails_without_required_fields(): void
    {
        $this->actingAs($this->user)
            ->post(route('employes.store'), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'type_employe', 'statut']);
    }

    public function test_store_fails_with_invalid_type_employe(): void
    {
        $this->actingAs($this->user)
            ->post(route('employes.store'), [
                'nom' => 'TEST', 'prenom' => 'Test',
                'type_employe' => 'stagiaire',
                'statut' => 'actif',
            ])
            ->assertSessionHasErrors('type_employe');
    }

    // ── Matricule uniqueness per org ──────────────────────────────────────────

    public function test_matricule_is_unique_per_organization(): void
    {
        $otherOrg = Organization::factory()->create();

        // Same matricule allowed across different orgs
        Employe::create(['organization_id' => $this->org->id,   'matricule' => '000001', 'nom' => 'A', 'prenom' => 'A', 'type_employe' => 'interne', 'statut' => 'actif']);
        Employe::create(['organization_id' => $otherOrg->id,    'matricule' => '000001', 'nom' => 'B', 'prenom' => 'B', 'type_employe' => 'interne', 'statut' => 'actif']);

        $this->assertDatabaseCount('employes', 2);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_employe(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->put(route('employes.update', $employe), [
                'nom'          => 'CAMARA',
                'prenom'       => 'Fatoumata',
                'type_employe' => 'externe',
                'statut'       => 'suspendu',
            ])
            ->assertRedirect(route('employes.edit', $employe));

        $this->assertDatabaseHas('employes', [
            'id'     => $employe->id,
            'nom'    => 'CAMARA',
            'statut' => 'suspendu',
        ]);
    }

    public function test_matricule_cannot_be_changed_via_update(): void
    {
        $employe = $this->makeEmploye(['matricule' => '000099']);

        $this->actingAs($this->user)
            ->put(route('employes.update', $employe), [
                'nom'          => $employe->nom,
                'prenom'       => $employe->prenom,
                'type_employe' => $employe->type_employe->value,
                'statut'       => $employe->statut->value,
                'matricule'    => '999999',
            ]);

        $this->assertSame('000099', $employe->fresh()->matricule);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg  = Organization::factory()->create();
        $employe   = $this->makeEmploye(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->put(route('employes.update', $employe), [
                'nom' => 'X', 'prenom' => 'X', 'type_employe' => 'interne', 'statut' => 'actif',
            ])
            ->assertStatus(403);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_employe(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->delete(route('employes.destroy', $employe))
            ->assertRedirect(route('employes.index'));

        $this->assertSoftDeleted('employes', ['id' => $employe->id]);
    }
}
