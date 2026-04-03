<?php

namespace Tests\Feature;

use App\Models\CommissionVente;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasVentesSetup;
use Tests\TestCase;

class CommissionVenteIndexTest extends TestCase
{
    use HasAdminSetup, HasVentesSetup, RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->user = $this->userWithPermissions($this->org);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('commissions.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('commissions.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('commissions.index'))
            ->assertStatus(403);
    }

    public function test_index_accepts_all_periode_values(): void
    {
        foreach (['today', 'week', 'month', 'all'] as $periode) {
            $this->actingAs($this->user)
                ->get(route('commissions.index', ['periode' => $periode]))
                ->assertStatus(200);
        }
    }

    public function test_index_returns_expected_inertia_data(): void
    {
        $this->actingAs($this->user)
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
        CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $otherOrg = Organization::factory()->create();
        CommissionVente::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->get(route('commissions.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('commissions', 1)
            );
    }

    public function test_index_totaux_keys_are_present(): void
    {
        $this->actingAs($this->user)
            ->get(route('commissions.index', ['periode' => 'all']))
            ->assertInertia(fn ($page) => $page
                ->where('totaux.nb_en_attente', 0)
                ->where('totaux.nb_versees', 0)
            );
    }
}
