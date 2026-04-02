<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private function userWithPermissions(Organization $org): User
    {
        return $this->makeUserWithPermissions($org, ['clients.read', 'clients.create', 'clients.update', 'clients.delete']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('clients.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertStatus(403);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_client_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('clients.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Aissatou',
                'telephone' => '+224622000010',
            ])
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseHas('clients', [
            'organization_id' => $org->id,
            'nom' => 'Diallo',
        ]);
    }

    public function test_store_fails_with_empty_nom_and_prenom(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('clients.store'), [])
            ->assertSessionHasErrors(['nom', 'prenom']);
    }

    public function test_store_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->post(route('clients.store'), [
                'nom' => 'Test',
                'prenom' => 'Client',
            ])
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_client_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->put(route('clients.update', $client), [
                'nom' => 'Barry',
                'prenom' => 'Mariama',
            ])
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'nom' => 'Barry',
        ]);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->put(route('clients.update', $client), [
                'nom' => 'Barry',
            ])
            ->assertStatus(403);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_client_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->delete(route('clients.destroy', $client))
            ->assertRedirect(route('clients.index'));

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->delete(route('clients.destroy', $client))
            ->assertStatus(403);
    }

    public function test_destroy_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();
        $client = Client::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->delete(route('clients.destroy', $client))
            ->assertStatus(403);
    }
}
