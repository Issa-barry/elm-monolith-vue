<?php

namespace Tests\Feature;

use App\Models\CommissionVente;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CommissionVenteIndexTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    private function userWithPermissions(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ventes.read', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('ventes.read');

        return $user;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('commissions.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('commissions.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('commissions.index'))
            ->assertStatus(403);
    }

    public function test_index_accepts_all_periode_values(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        foreach (['today', 'week', 'month', 'all'] as $periode) {
            $this->actingAs($user)
                ->get(route('commissions.index', ['periode' => $periode]))
                ->assertStatus(200);
        }
    }

    public function test_index_returns_expected_inertia_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('commissions.index'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('commissions')
                ->has('totaux')
                ->has('modes_paiement')
                ->has('periode')
            );
    }

    public function test_index_only_shows_commissions_for_own_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $ownCommission = CommissionVente::factory()->create(['organization_id' => $org->id]);

        $otherOrg = Organization::factory()->create();
        CommissionVente::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->get(route('commissions.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('commissions', 1)
            );
    }

    public function test_index_totaux_keys_are_present(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('commissions.index', ['periode' => 'all']))
            ->assertInertia(fn ($page) => $page
                ->where('totaux.nb_en_attente', 0)
                ->where('totaux.nb_versees', 0)
            );
    }
}
