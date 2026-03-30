<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        return $user;
    }

    private function staffUser(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_client_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->clientUser($org);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('client.dashboard'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_for_staff_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(403);
    }
}
