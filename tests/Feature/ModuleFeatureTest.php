<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ModuleFeatureTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function staffUser(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);

        $perms = [
            'ventes.read', 'ventes.create',
            'achats.read', 'achats.create',
            'packings.read',
            'prestataires.read',
            'vehicules.read',
            'produits.read',
            'sites.read',
            'users.read',
            'parametres.update',
        ];

        foreach ($perms as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($perms);

        return $user;
    }

    // ── Module Ventes ─────────────────────────────────────────────────────────

    public function test_ventes_route_accessible_when_module_active(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);
        Feature::for($org)->activate(ModuleFeature::VENTES);

        $this->actingAs($user)
            ->get(route('ventes.index'))
            ->assertStatus(200);
    }

    public function test_ventes_route_returns_403_when_module_disabled(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);
        Feature::for($org)->deactivate(ModuleFeature::VENTES);

        $this->actingAs($user)
            ->get(route('ventes.index'))
            ->assertStatus(403);
    }

    // ── Module Achats ─────────────────────────────────────────────────────────

    public function test_achats_route_accessible_when_module_active(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);
        Feature::for($org)->activate(ModuleFeature::ACHATS);

        $this->actingAs($user)
            ->get(route('achats.index'))
            ->assertStatus(200);
    }

    public function test_achats_route_returns_403_when_module_disabled(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);
        Feature::for($org)->deactivate(ModuleFeature::ACHATS);

        $this->actingAs($user)
            ->get(route('achats.index'))
            ->assertStatus(403);
    }

    // ── Module Packings ───────────────────────────────────────────────────────

    public function test_packings_route_returns_403_when_module_disabled(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);
        Feature::for($org)->deactivate(ModuleFeature::PACKINGS);

        $this->actingAs($user)
            ->get(route('packings.index'))
            ->assertStatus(403);
    }

    // ── Module Produits ───────────────────────────────────────────────────────

    public function test_produits_route_returns_403_when_module_disabled(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);
        Feature::for($org)->deactivate(ModuleFeature::PRODUITS);

        $this->actingAs($user)
            ->get(route('produits.index'))
            ->assertStatus(403);
    }

    // ── Module Sites ──────────────────────────────────────────────────────────

    public function test_sites_route_returns_403_when_module_disabled(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);
        Feature::for($org)->deactivate(ModuleFeature::SITES);

        $this->actingAs($user)
            ->get(route('sites.index'))
            ->assertStatus(403);
    }

    // ── Flags partagés via Inertia ────────────────────────────────────────────

    public function test_module_flags_shared_in_inertia_props(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);

        Feature::for($org)->deactivate(ModuleFeature::VENTES);
        Feature::for($org)->activate(ModuleFeature::ACHATS);

        // Les clés dans module_flags sont simplifiées (sans préfixe 'module.')
        // ex: 'module.ventes' → 'ventes'
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('module_flags.ventes', false)
                ->where('module_flags.achats', true),
            );
    }

    // ── Toggle admin ──────────────────────────────────────────────────────────

    public function test_admin_can_toggle_module_off(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);

        // S'assurer que le module est actif d'abord
        Feature::for($org)->activate(ModuleFeature::SITES);

        $this->actingAs($user)
            ->patch(route('modules.toggle'), [
                'module' => ModuleFeature::SITES,
                'active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse(Feature::for($org)->active(ModuleFeature::SITES));
    }

    public function test_admin_can_toggle_module_on(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);

        Feature::for($org)->deactivate(ModuleFeature::SITES);

        $this->actingAs($user)
            ->patch(route('modules.toggle'), [
                'module' => ModuleFeature::SITES,
                'active' => true,
            ])
            ->assertRedirect();

        $this->assertTrue(Feature::for($org)->active(ModuleFeature::SITES));
    }

    public function test_toggle_requires_parametres_update_permission(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        // Pas de permission parametres.update

        $this->actingAs($user)
            ->patch(route('modules.toggle'), [
                'module' => ModuleFeature::VENTES,
                'active' => false,
            ])
            ->assertStatus(403);
    }

    // ── Valeur par défaut ─────────────────────────────────────────────────────

    public function test_modules_are_active_by_default_for_new_org(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);

        // Sans entrée en base, le module doit être actif (valeur par défaut = true)
        foreach (ModuleFeature::ALL as $module) {
            $this->assertTrue(
                Feature::for($org)->active($module),
                "Le module {$module} devrait être actif par défaut",
            );
        }
    }
}
