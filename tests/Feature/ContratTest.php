<?php

namespace Tests\Feature;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
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

        $this->org  = Organization::factory()->create();
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

        return Employe::create(array_merge([
            'organization_id' => $this->org->id,
            'matricule'       => str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'nom'             => 'DIALLO',
            'prenom'          => 'Mamadou',
            'type_employe'    => 'interne',
            'statut'          => 'actif',
        ], $overrides));
    }

    private function makeContrat(Employe $employe, array $overrides = []): Contrat
    {
        return Contrat::create(array_merge([
            'organization_id' => $this->org->id,
            'employe_id'      => $employe->id,
            'type_contrat'    => TypeContrat::CDI->value,
            'date_debut'      => now()->toDateString(),
            'statut_contrat'  => StatutContrat::ACTIF->value,
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
                'employe_id'   => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut'   => '2025-01-01',
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
                'employe_id'   => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut'   => '2025-01-01',
                'date_fin'     => '2026-12-31',
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
                'employe_id'   => $employe->id,
                'type_contrat' => 'cdd',
                'date_debut'   => '2025-01-01',
            ])
            ->assertSessionHasErrors('date_fin');
    }

    public function test_store_cdd_requires_date_fin_after_or_equal_date_debut(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id'   => $employe->id,
                'type_contrat' => 'cdd',
                'date_debut'   => '2025-06-01',
                'date_fin'     => '2025-01-01',
            ])
            ->assertSessionHasErrors('date_fin');
    }

    public function test_store_cdd_creates_contrat_with_date_fin(): void
    {
        $employe = $this->makeEmploye();

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id'   => $employe->id,
                'type_contrat' => 'cdd',
                'date_debut'   => '2025-01-01',
                'date_fin'     => '2025-12-31',
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
                'employe_id'   => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut'   => '2025-06-01',
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
                'employe_id'   => $employe->id,
                'type_contrat' => 'cdi',
                'date_debut'   => '2025-06-01',
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
                'employe_id'   => $employe->id,
                'type_contrat' => 'ctt',
                'date_debut'   => '2025-01-01',
            ])
            ->assertSessionHasErrors('type_contrat');
    }

    // ── Isolation organisation ────────────────────────────────────────────────

    public function test_store_rejects_employe_from_other_organization(): void
    {
        $otherOrg    = Organization::factory()->create();
        $otherEmploye = Employe::create([
            'organization_id' => $otherOrg->id,
            'matricule'       => '999999',
            'nom'             => 'Autre',
            'prenom'          => 'Org',
            'type_employe'    => 'interne',
            'statut'          => 'actif',
        ]);

        $this->actingAs($this->user)
            ->post(route('contrats.store'), [
                'employe_id'   => $otherEmploye->id,
                'type_contrat' => 'cdi',
                'date_debut'   => '2025-01-01',
            ])
            ->assertStatus(404);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg    = Organization::factory()->create();
        $otherEmploye = Employe::create([
            'organization_id' => $otherOrg->id,
            'matricule'       => '999998',
            'nom'             => 'Autre',
            'prenom'          => 'Org',
            'type_employe'    => 'interne',
            'statut'          => 'actif',
        ]);
        $contrat = $this->makeContrat($otherEmploye, ['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [
                'employe_id'   => $otherEmploye->id,
                'type_contrat' => 'cdi',
                'date_debut'   => '2025-01-01',
            ])
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_contrat(): void
    {
        $employe = $this->makeEmploye();
        $contrat = $this->makeContrat($employe, [
            'type_contrat' => 'cdd',
            'date_debut'   => '2025-01-01',
            'date_fin'     => '2025-06-30',
        ]);

        $this->actingAs($this->user)
            ->put(route('contrats.update', $contrat), [
                'employe_id'    => $employe->id,
                'type_contrat'  => 'cdd',
                'date_debut'    => '2025-01-01',
                'date_fin'      => '2025-12-31',
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
