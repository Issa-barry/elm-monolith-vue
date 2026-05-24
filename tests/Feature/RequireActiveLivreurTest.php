<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RequireActiveLivreurTest extends TestCase
{
    use RefreshDatabase;

    private function makeLivreurUser(Organization $org, bool $isActive): User
    {
        Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('livreur');

        Livreur::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'telephone' => $user->telephone,
            'is_active' => $isActive,
        ]);

        return $user;
    }

    // ── Livreur inactif ───────────────────────────────────────────────────────

    public function test_inactive_livreur_is_redirected_to_pending_page(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, false);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertRedirect(route('client.pending'));
    }

    public function test_inactive_livreur_can_access_pending_page(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, false);

        $this->actingAs($user)
            ->get(route('client.pending'))
            ->assertStatus(200);
    }

    // ── Livreur actif ─────────────────────────────────────────────────────────

    public function test_active_livreur_can_access_dashboard(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, true);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(200);
    }

    public function test_active_livreur_on_pending_page_is_redirected_to_dashboard(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, true);

        $this->actingAs($user)
            ->get(route('client.pending'))
            ->assertRedirect(route('client.dashboard'));
    }

    // ── Livreur également propriétaire ───────────────────────────────────────

    public function test_livreur_proprietaire_bypasses_pending_check(): void
    {
        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);

        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, false); // livreur inactif
        $user->assignRole('proprietaire');

        Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(200);
    }

    // ── Non-livreur non affecté ───────────────────────────────────────────────

    public function test_client_user_is_not_affected_by_middleware(): void
    {
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(200);
    }
}
