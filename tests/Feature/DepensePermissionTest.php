<?php

namespace Tests\Feature;

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
 * Vérifie que les droits Dépenses sont cohérents entre seeder, policy et UI.
 *
 * - index  → depenses.read  (viewAny dans DepensePolicy)
 * - create → depenses.create (create dans DepensePolicy)
 * - can_create prop → depenses.create (contrôle le bouton "Nouvelle dépense")
 */
class DepensePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeManager(array $permissions): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_manager_with_read_can_access_depenses_index(): void
    {
        $manager = $this->makeManager(['depenses.read']);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Depenses/Index'));
    }

    public function test_manager_without_read_gets_403_on_depenses_index(): void
    {
        $manager = $this->makeManager([]);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertForbidden();
    }

    // ── can_create prop ───────────────────────────────────────────────────────

    public function test_manager_with_create_sees_can_create_true(): void
    {
        $manager = $this->makeManager(['depenses.read', 'depenses.create']);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_create', true));
    }

    public function test_manager_with_only_read_sees_can_create_false(): void
    {
        $manager = $this->makeManager(['depenses.read']);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_create', false));
    }

    // ── Create / Store ────────────────────────────────────────────────────────

    public function test_manager_with_create_can_access_create_form(): void
    {
        $manager = $this->makeManager(['depenses.read', 'depenses.create']);

        $this->actingAs($manager)
            ->get(route('depenses.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Depenses/Create'));
    }

    public function test_manager_without_create_gets_403_on_create_form(): void
    {
        $manager = $this->makeManager(['depenses.read']);

        $this->actingAs($manager)
            ->get(route('depenses.create'))
            ->assertForbidden();
    }

    public function test_manager_without_create_cannot_store_depense(): void
    {
        $manager = $this->makeManager(['depenses.read']);

        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->actingAs($manager)
            ->post(route('depenses.store'), [
                'depense_type_id' => $type->id,
                'site_id' => $this->site->id,
                'montant' => 5000,
                'date_depense' => now()->toDateString(),
                'statut' => 'brouillon',
            ])
            ->assertForbidden();
    }

    public function test_manager_cannot_store_depense_on_unassigned_site(): void
    {
        $otherSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Agence',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $manager = $this->makeManager(['depenses.read', 'depenses.create']);

        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->actingAs($manager)
            ->post(route('depenses.store'), [
                'depense_type_id' => $type->id,
                'site_id' => $otherSite->id,
                'montant' => 5000,
                'date_depense' => now()->toDateString(),
                'statut' => 'brouillon',
            ])
            ->assertForbidden();
    }

    public function test_manager_can_store_depense_on_their_site(): void
    {
        $manager = $this->makeManager(['depenses.read', 'depenses.create']);

        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant',
            'code' => 'carb',
        ]);

        $this->actingAs($manager)
            ->post(route('depenses.store'), [
                'depense_type_id' => $type->id,
                'site_id' => $this->site->id,
                'montant' => 5000,
                'date_depense' => now()->toDateString(),
                'statut' => 'brouillon',
            ])
            ->assertRedirect(route('depenses.index'));
    }
}

