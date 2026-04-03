<?php

namespace Tests\Feature;

use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasVentesSetup;
use Tests\TestCase;

class FactureVenteTest extends TestCase
{
    use HasAdminSetup, HasVentesSetup, RefreshDatabase;

    private Organization $org;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org  = Organization::factory()->create();
        $this->user = $this->userWithPermissions($this->org);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('factures.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('factures.index'))
            ->assertStatus(403);
    }

    public function test_index_accepts_periode_parameter(): void
    {
        foreach (['today', 'week', 'month', 'all'] as $periode) {
            $this->actingAs($this->user)
                ->get(route('factures.index', ['periode' => $periode]))
                ->assertStatus(200);
        }
    }

    public function test_index_only_shows_factures_for_own_organization(): void
    {
        $ownCommande = CommandeVente::factory()->create(['organization_id' => $this->org->id]);
        FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $ownCommande->id,
            'montant_net' => 10000,
        ]);

        $otherOrg = Organization::factory()->create();
        $otherCommande = CommandeVente::factory()->create(['organization_id' => $otherOrg->id]);
        FactureVente::factory()->create([
            'organization_id' => $otherOrg->id,
            'commande_vente_id' => $otherCommande->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('factures')
            );
    }

    public function test_index_shows_correct_totaux(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('totaux')
                ->has('modes_paiement')
                ->has('periode')
            );
    }
}
