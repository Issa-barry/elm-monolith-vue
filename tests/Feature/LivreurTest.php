<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LivreurTest extends TestCase
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
        foreach (['livreurs.read', 'livreurs.create', 'livreurs.update', 'livreurs.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['livreurs.read', 'livreurs.create', 'livreurs.update', 'livreurs.delete']);

        return $user;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('livreurs.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('livreurs.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('livreurs.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('livreurs.create'))
            ->assertStatus(200);
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('livreurs.create'))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_livreur_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('livreurs.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => true,
            ])
            ->assertRedirect(route('livreurs.index'));

        $this->assertDatabaseHas('livreurs', [
            'nom' => 'DIALLO',
            'organization_id' => $org->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('livreurs.store'), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'telephone', 'code_pays', 'ville']);
    }

    public function test_store_fails_with_invalid_code_pays(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('livreurs.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000001',
                'code_pays' => 'XX',
                'ville' => 'Conakry',
            ])
            ->assertSessionHasErrors('code_pays');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('livreurs.edit', $livreur))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->get(route('livreurs.edit', $livreur))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_livreur_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->put(route('livreurs.update', $livreur), [
                'nom' => 'Barry',
                'prenom' => 'Fatoumata',
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
                'is_active' => true,
            ])
            ->assertRedirect(route('livreurs.index'));

        $this->assertDatabaseHas('livreurs', [
            'id' => $livreur->id,
            'nom' => 'BARRY',
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->put(route('livreurs.update', $livreur), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'telephone', 'code_pays', 'ville']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_livreur_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->delete(route('livreurs.destroy', $livreur))
            ->assertRedirect(route('livreurs.index'));

        $this->assertSoftDeleted('livreurs', ['id' => $livreur->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->delete(route('livreurs.destroy', $livreur))
            ->assertStatus(403);
    }
}
