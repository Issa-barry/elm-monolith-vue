<?php

namespace Tests\Feature;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContratTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
        $this->user = $this->makeUser([
            'rh-contrats.read', 'rh-contrats.create', 'rh-contrats.update', 'rh-contrats.delete',
            'rh-employes.read',
        ]);
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
        static $seq = 0;
        $seq++;

        $orgId = $overrides['organization_id'] ?? $this->org->id;

        $personne = Personne::create([
            'organization_id' => $orgId,
            'nom' => $overrides['nom'] ?? 'DIALLO',
            'prenom' => $overrides['prenom'] ?? 'Mamadou',
            'telephone' => $overrides['telephone'] ?? ('+224'.fake()->unique()->numerify('#########')),
        ]);

        unset($overrides['nom'], $overrides['prenom'], $overrides['telephone']);

        return Employe::create(array_merge([
            'organization_id' => $orgId,
            'personne_id' => $personne->id,
            'matricule' => str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'type_employe' => 'interne',
            'statut' => 'actif',
        ], $overrides));
    }

    private function makeContrat(Employe $employe, array $overrides = []): Contrat
    {
        return Contrat::create(array_merge([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_contrat' => TypeContrat::CDI->value,
            'date_debut' => now()->toDateString(),
            'statut_contrat' => StatutContrat::ACTIF->value,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('contrats.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated(): void
    {
        $this->get(route('contrats.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        $this->actingAs($user)
            ->get(route('contrats.index'))
            ->assertStatus(403);
    }

    // ── store : règles CDI ────────────────────────────────────────────────────

    public function test_store_cdi_creates_contrat_without_date_fin(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
            ])
            ->assertRedirect(route('employes.edit', $employe));

        $contrat = Contrat::where('employe_id', $employe->id)->firstOrFail();
        $this->assertNull($contrat->date_fin);
        $this->assertSame('cdi', $contrat->type_contrat->value);
        $this->assertSame('actif', $contrat->statut_contrat->value);
    }

    public function test_store_cdi_ignores_date_fin_even_if_provided(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
                'date_fin' => '2026-12-31',
            ])
            ->assertRedirect(route('employes.edit', $employe));

        $this->assertNull(Contrat::where('employe_id', $employe->id)->firstOrFail()->date_fin);
    }

    // ── store : règles CDD ────────────────────────────────────────────────────

    public function test_store_cdd_requires_date_fin(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdd',
                'date_debut' => '2025-01-01',
            ])
            ->assertSessionHasErrors('date_fin');
    }

    public function test_store_cdd_requires_date_fin_after_or_equal_date_debut(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdd',
                'date_debut' => '2025-06-01',
                'date_fin' => '2025-01-01',
            ])
            ->assertSessionHasErrors('date_fin');
    }

    public function test_store_cdd_creates_contrat_with_date_fin(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdd',
                'date_debut' => '2025-01-01',
                'date_fin' => '2025-12-31',
            ])
            ->assertRedirect(route('employes.edit', $employe));

        $contrat = Contrat::where('employe_id', $employe->id)->firstOrFail();
        $this->assertSame('2025-12-31', $contrat->date_fin->format('Y-m-d'));
    }

    // ── Contrat actif unique ──────────────────────────────────────────────────

    public function test_cannot_create_second_active_contrat_for_same_employe(): void
    {
        $employe = $this->makeEmploye();
        $this->makeContrat($employe);

        $response = $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-06-01',
            ]);

        $response->assertSessionHasErrors('employe_id');
        $this->assertDatabaseCount('contrats', 1);
    }

    public function test_can_create_contrat_after_previous_one_is_terminated(): void
    {
        $employe = $this->makeEmploye();
        $this->makeContrat($employe, ['statut_contrat' => StatutContrat::TERMINE->value]);

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-06-01',
            ])
            ->assertRedirect(route('employes.edit', $employe));

        $this->assertDatabaseCount('contrats', 2);
    }

    // ── store : validation générale ──────────────────────────────────────────

    public function test_store_fails_without_required_fields(): void
    {
        $this->actingAs($this->user)
            ->post(route('contrats.store'), [])
            ->assertSessionHasErrors(['employe_id', 'type_contrat', 'date_debut']);
    }

    public function test_store_fails_with_invalid_type_contrat(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'ctt',
                'date_debut' => '2025-01-01',
            ])
            ->assertSessionHasErrors('type_contrat');
    }

    // ── Isolation organisation ────────────────────────────────────────────────

    public function test_store_rejects_employe_from_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherEmploye = $this->makeEmploye(['organization_id' => $otherOrg->id, 'matricule' => '999999', 'nom' => 'Autre', 'prenom' => 'Org']);

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $otherEmploye->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
            ])
            ->assertStatus(404);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherEmploye = $this->makeEmploye(['organization_id' => $otherOrg->id, 'matricule' => '999998', 'nom' => 'Autre', 'prenom' => 'Org']);
        $contrat = $this->makeContrat($otherEmploye, ['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [
                'employe_id' => $otherEmploye->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
            ])
            ->assertStatus(403);
    }

    // ── update : organisation et contrat actif unique ─────────────────────────

    public function test_update_refuse_de_rattacher_le_contrat_a_un_employe_d_une_autre_organisation(): void
    {
        $employe = $this->makeEmploye();
        $contrat = $this->makeContrat($employe);
        $otherOrg = Organization::factory()->create();
        $otherEmploye = $this->makeEmploye(['organization_id' => $otherOrg->id, 'matricule' => '999997']);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [
                'employe_id' => $otherEmploye->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
            ])
            ->assertStatus(404);

        $this->assertSame($employe->id, $contrat->fresh()->employe_id);
    }

    public function test_update_refuse_d_activer_un_second_contrat(): void
    {
        $employe = $this->makeEmploye();
        $this->makeContrat($employe);
        $ancien = $this->makeContrat($employe, ['statut_contrat' => StatutContrat::TERMINE->value, 'date_debut' => '2024-01-01']);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $ancien), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2024-01-01',
                'statut_contrat' => 'actif',
            ])
            ->assertSessionHasErrors('employe_id');

        $this->assertSame(StatutContrat::TERMINE, $ancien->fresh()->statut_contrat);
    }

    public function test_update_refuse_de_deplacer_un_contrat_actif_vers_un_employe_qui_en_a_deja_un(): void
    {
        $employeA = $this->makeEmploye();
        $employeB = $this->makeEmploye();
        $contratA = $this->makeContrat($employeA);
        $this->makeContrat($employeB);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contratA), [
                'employe_id' => $employeB->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
            ])
            ->assertSessionHasErrors('employe_id');

        $this->assertSame($employeA->id, $contratA->fresh()->employe_id);
    }

    public function test_update_sans_statut_conserve_le_statut_actuel(): void
    {
        $employe = $this->makeEmploye();
        $contrat = $this->makeContrat($employe);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-02-01',
            ])
            ->assertRedirect(route('employes.edit', $employe));

        $fresh = $contrat->fresh();
        $this->assertSame(StatutContrat::ACTIF, $fresh->statut_contrat);
        $this->assertSame('2025-02-01', $fresh->date_debut->format('Y-m-d'));
    }

    // ── Salaire : réservé à rh-remunerations.read (27/10/2026) ───────────────

    public function test_sans_rh_remunerations_read_le_salaire_n_est_ni_saisi_ni_affiche(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
                'salaire_base' => 3_000_000,
            ])
            ->assertSessionHasNoErrors();

        $contrat = Contrat::where('employe_id', $employe->id)->firstOrFail();
        $this->assertNull($contrat->salaire_base);
    }

    public function test_sans_rh_remunerations_read_une_modification_garde_le_salaire_existant(): void
    {
        $employe = $this->makeEmploye();
        $contrat = $this->makeContrat($employe, ['salaire_base' => 2_000_000]);

        $this->actingAs($this->user)
            ->get(route('contrats.edit', $contrat))
            ->assertInertia(fn ($page) => $page->where('contrat.salaire_base', null));

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
                'salaire_base' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(2_000_000, (float) $contrat->fresh()->salaire_base);
    }

    public function test_avec_rh_remunerations_read_le_salaire_est_saisi_et_affiche(): void
    {
        Permission::firstOrCreate(['name' => 'rh-remunerations.read', 'guard_name' => 'web']);
        $this->user->givePermissionTo('rh-remunerations.read');
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut' => '2025-01-01',
                'salaire_base' => 3_000_000,
            ])
            ->assertSessionHasNoErrors();

        $contrat = Contrat::where('employe_id', $employe->id)->firstOrFail();
        $this->assertEquals(3_000_000, (float) $contrat->salaire_base);

        $this->actingAs($this->user)
            ->get(route('contrats.edit', $contrat))
            ->assertInertia(fn ($page) => $page->where('contrat.salaire_base', fn ($v) => (float) $v === 3_000_000.0));
    }

    /**
     * Le champ formaté (« 750 500 ») envoie un nombre propre ; un champ vidé envoie null. Un texte
     * avec séparateurs n'est jamais accepté tel quel par le serveur.
     */
    public function test_le_salaire_est_recu_comme_un_nombre_sans_separateur(): void
    {
        Permission::firstOrCreate(['name' => 'rh-remunerations.read', 'guard_name' => 'web']);
        $this->user->givePermissionTo('rh-remunerations.read');
        $employe = $this->makeEmploye();
        $donnees = ['employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2025-01-01'];

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [...$donnees, 'salaire_base' => '750 000'])
            ->assertSessionHasErrors('salaire_base');
        $this->assertSame(0, Contrat::where('employe_id', $employe->id)->count());

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [...$donnees, 'salaire_base' => 750_500])
            ->assertSessionHasNoErrors();
        $contrat = Contrat::where('employe_id', $employe->id)->sole();
        $this->assertEquals(750_500, (float) $contrat->salaire_base);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [...$donnees, 'salaire_base' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($contrat->fresh()->salaire_base);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_contrat(): void
    {
        $employe = $this->makeEmploye();
        $contrat = $this->makeContrat($employe, [
            'type_contrat' => 'cdd',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-06-30',
        ]);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [
                'employe_id' => $employe->id,
                'type_contrat' => 'cdd',
                'date_debut' => '2025-01-01',
                'date_fin' => '2025-12-31',
                'statut_contrat' => 'termine',
            ])
            ->assertRedirect(route('employes.edit', $employe));

        $fresh = $contrat->fresh();
        $this->assertSame('2025-12-31', $fresh->date_fin->format('Y-m-d'));
        $this->assertSame('termine', $fresh->statut_contrat->value);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_contrat(): void
    {
        $employe = $this->makeEmploye();
        $contrat = $this->makeContrat($employe);

        $this->actingAs($this->user)
            ->delete(route('contrats.destroy', $contrat))
            ->assertRedirect(route('employes.edit', $employe));

        $this->assertSoftDeleted('contrats', ['id' => $contrat->id]);
    }
}
