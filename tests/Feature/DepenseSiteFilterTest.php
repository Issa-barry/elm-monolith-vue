<?php

namespace Tests\Feature;

use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vérifie que le champ Site est filtré et verrouillé pour les non-admins
 * en création ET en modification.
 *
 * - create/edit : admin voit can_change_site=true + tous les sites
 * - create/edit : non-admin voit can_change_site=false + uniquement ses sites assignés
 * - store : non-admin ne peut pas forcer un site non assigné
 * - update : non-admin ne peut pas changer de site via l'API
 */
class DepenseSiteFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $siteA;

    private Site $siteB;

    private DepenseType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->siteA = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence A',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->siteB = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence B',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $this->type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
        ]);

        foreach (['admin_entreprise', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        foreach (['depenses.read', 'depenses.create', 'depenses.update'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['depenses.read', 'depenses.create', 'depenses.update']);
        $user->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function managerUser(): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['depenses.read', 'depenses.create', 'depenses.update']);
        // Attaché uniquement à siteA
        $user->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function depenseBrouillon(User $user, ?Site $site = null): Depense
    {
        return Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => ($site ?? $this->siteA)->id,
        ]);
    }

    private function updatePayload(array $override = []): array
    {
        return array_merge([
            'depense_type_id' => $this->type->id,
            'site_id' => $this->siteA->id,
            'montant' => 10000,
            'date_depense' => now()->toDateString(),
            'commentaire' => null,
            'statut' => 'brouillon',
        ], $override);
    }

    // ── Create page ───────────────────────────────────────────────────────────

    public function test_create_admin_recoit_can_change_site_true(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('depenses.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Create')
                ->where('can_change_site', true)
            );
    }

    public function test_create_non_admin_recoit_can_change_site_false(): void
    {
        $this->actingAs($this->managerUser())
            ->get(route('depenses.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_change_site', false)
            );
    }

    public function test_create_non_admin_ne_voit_que_ses_sites(): void
    {
        $manager = $this->managerUser(); // attaché à siteA uniquement

        $this->actingAs($manager)
            ->get(route('depenses.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $this->siteA->id)
            );
    }

    public function test_create_admin_voit_tous_les_sites(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('depenses.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 2) // siteA + siteB
            );
    }

    // ── Edit page ─────────────────────────────────────────────────────────────

    public function test_edit_admin_recoit_can_change_site_true(): void
    {
        $admin = $this->adminUser();
        $depense = $this->depenseBrouillon($admin);

        $this->actingAs($admin)
            ->get(route('depenses.edit', $depense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Edit')
                ->where('can_change_site', true)
            );
    }

    public function test_edit_non_admin_recoit_can_change_site_false(): void
    {
        $manager = $this->managerUser();
        $depense = $this->depenseBrouillon($manager);

        $this->actingAs($manager)
            ->get(route('depenses.edit', $depense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_change_site', false)
            );
    }

    public function test_edit_non_admin_ne_voit_que_ses_sites(): void
    {
        $manager = $this->managerUser();
        $depense = $this->depenseBrouillon($manager);

        $this->actingAs($manager)
            ->get(route('depenses.edit', $depense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $this->siteA->id)
            );
    }

    public function test_edit_admin_voit_tous_les_sites(): void
    {
        $admin = $this->adminUser();
        $depense = $this->depenseBrouillon($admin);

        $this->actingAs($admin)
            ->get(route('depenses.edit', $depense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 2)
            );
    }

    // ── Update — blocage site non autorisé ────────────────────────────────────

    public function test_update_non_admin_ne_peut_pas_choisir_site_non_assigne(): void
    {
        $manager = $this->managerUser();
        $depense = $this->depenseBrouillon($manager, $this->siteA);

        // Tente de passer à siteB (non assigné)
        $this->actingAs($manager)
            ->put(route('depenses.update', $depense), $this->updatePayload([
                'site_id' => $this->siteB->id,
            ]))
            ->assertForbidden();

        // Site inchangé en base
        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'site_id' => $this->siteA->id,
        ]);
    }

    public function test_update_admin_peut_choisir_nimporte_quel_site(): void
    {
        $admin = $this->adminUser();
        $depense = $this->depenseBrouillon($admin, $this->siteA);

        // L'admin change vers siteB
        $this->actingAs($admin)
            ->put(route('depenses.update', $depense), $this->updatePayload([
                'site_id' => $this->siteB->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'site_id' => $this->siteB->id,
        ]);
    }

    public function test_update_non_admin_peut_conserver_son_site(): void
    {
        $manager = $this->managerUser();
        $depense = $this->depenseBrouillon($manager, $this->siteA);

        // Conserve siteA (son site) : doit passer
        $this->actingAs($manager)
            ->put(route('depenses.update', $depense), $this->updatePayload([
                'site_id' => $this->siteA->id,
                'montant' => 99999,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'montant' => 99999,
            'site_id' => $this->siteA->id,
        ]);
    }
}
