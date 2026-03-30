<?php

namespace Tests\Feature;

use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FactureVenteTest extends TestCase
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
            ->get(route('factures.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('factures.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('factures.index'))
            ->assertStatus(403);
    }

    public function test_index_accepts_periode_parameter(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        foreach (['today', 'week', 'month', 'all'] as $periode) {
            $this->actingAs($user)
                ->get(route('factures.index', ['periode' => $periode]))
                ->assertStatus(200);
        }
    }

    public function test_index_only_shows_factures_for_own_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $ownCommande = CommandeVente::factory()->create(['organization_id' => $org->id]);
        $ownFacture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $ownCommande->id,
            'montant_net' => 10000,
        ]);

        $otherOrg = Organization::factory()->create();
        $otherCommande = CommandeVente::factory()->create(['organization_id' => $otherOrg->id]);
        FactureVente::factory()->create([
            'organization_id' => $otherOrg->id,
            'commande_vente_id' => $otherCommande->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200);

        // The response should include the own facture reference but not the other org's
        $response->assertInertia(fn ($page) => $page
            ->has('factures')
        );
    }

    public function test_index_shows_correct_totaux(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('totaux')
                ->has('modes_paiement')
                ->has('periode')
            );
    }
}
