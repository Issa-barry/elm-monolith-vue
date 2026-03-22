<?php

namespace Tests\Feature\Authorization;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org      = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
    }

    private function makeUser(string $role, ?int $orgId = null): User
    {
        $user = User::factory()->create(['organization_id' => $orgId]);
        $user->assignRole($role);

        return $user;
    }

    // ── Non authentifié ───────────────────────────────────────────────────────

    public function test_visiteur_non_connecte_est_redirige(): void
    {
        $this->get(route('clients.index'))->assertRedirect();
    }

    // ── Super Admin bypass ────────────────────────────────────────────────────

    public function test_super_admin_peut_lister_les_clients(): void
    {
        $admin = $this->makeUser('super_admin');

        $this->actingAs($admin)
            ->get(route('clients.index'))
            ->assertOk();
    }

    public function test_super_admin_peut_supprimer_client_autre_org(): void
    {
        $admin  = $this->makeUser('super_admin');
        $client = Client::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->actingAs($admin)
            ->delete(route('clients.destroy', $client))
            ->assertRedirect();
    }

    // ── Admin Entreprise ──────────────────────────────────────────────────────

    public function test_admin_entreprise_peut_lister_les_clients(): void
    {
        $admin = $this->makeUser('admin_entreprise', $this->org->id);

        $this->actingAs($admin)
            ->get(route('clients.index'))
            ->assertOk();
    }

    public function test_admin_entreprise_ne_peut_pas_supprimer_client_autre_org(): void
    {
        $admin  = $this->makeUser('admin_entreprise', $this->org->id);
        $client = Client::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->actingAs($admin)
            ->delete(route('clients.destroy', $client))
            ->assertForbidden();
    }

    // ── Commerciale ───────────────────────────────────────────────────────────

    public function test_commerciale_peut_voir_liste_clients(): void
    {
        $user = $this->makeUser('commerciale', $this->org->id);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk();
    }

    public function test_commerciale_ne_peut_pas_supprimer_client(): void
    {
        $user   = $this->makeUser('commerciale', $this->org->id);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)
            ->delete(route('clients.destroy', $client))
            ->assertForbidden();
    }

    public function test_commerciale_ne_peut_pas_voir_client_autre_org(): void
    {
        $user   = $this->makeUser('commerciale', $this->org->id);
        $client = Client::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertForbidden();
    }

    // ── Comptable ─────────────────────────────────────────────────────────────

    public function test_comptable_peut_lister_clients(): void
    {
        $user = $this->makeUser('comptable', $this->org->id);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk();
    }

    public function test_comptable_ne_peut_pas_creer_client(): void
    {
        $user = $this->makeUser('comptable', $this->org->id);

        $this->actingAs($user)
            ->post(route('clients.store'), [
                'nom'    => 'Test',
                'prenom' => 'Comptable',
            ])
            ->assertForbidden();
    }

    public function test_comptable_ne_peut_pas_modifier_client(): void
    {
        $user   = $this->makeUser('comptable', $this->org->id);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)
            ->put(route('clients.update', $client), ['nom' => 'Modif'])
            ->assertForbidden();
    }

    // ── Isolation multi-organisation ──────────────────────────────────────────

    public function test_admin_entreprise_ne_peut_pas_voir_detail_client_autre_org(): void
    {
        $admin  = $this->makeUser('admin_entreprise', $this->org->id);
        $client = Client::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->actingAs($admin)
            ->get(route('clients.show', $client))
            ->assertForbidden();
    }

    // ── permissionsMap cohérence ──────────────────────────────────────────────

    public function test_comptable_permissions_map_coherente(): void
    {
        $user = $this->makeUser('comptable', $this->org->id);
        $map  = $user->permissionsMap();

        $this->assertTrue($map['clients.read']);
        $this->assertFalse($map['clients.create']);
        $this->assertFalse($map['clients.delete']);
    }

    public function test_super_admin_a_toutes_permissions_a_true(): void
    {
        $admin = $this->makeUser('super_admin');
        $map   = $admin->permissionsMap();

        foreach ($map as $key => $value) {
            $this->assertTrue($value, "Permission {$key} devrait être true pour super_admin");
        }
    }
}
