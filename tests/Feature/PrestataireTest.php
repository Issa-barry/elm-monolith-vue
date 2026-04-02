<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Prestataire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrestataireTest extends TestCase
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
        foreach (['prestataires.read', 'prestataires.create', 'prestataires.update', 'prestataires.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['prestataires.read', 'prestataires.create', 'prestataires.update', 'prestataires.delete']);

        return $user;
    }

    private function makePrestataire(Organization $org, array $overrides = []): Prestataire
    {
        return Prestataire::create(array_merge([
            'organization_id' => $org->id,
            'nom' => 'FOURNISSEUR TEST',
            'type' => 'fournisseur',
            'is_active' => true,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('prestataires.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('prestataires.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('prestataires.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('prestataires.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_prestataire_with_nom_prenom_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('prestataires.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'type' => 'fournisseur',
                'is_active' => true,
            ])
            ->assertRedirect(route('prestataires.index'));

        $this->assertDatabaseHas('prestataires', [
            'organization_id' => $org->id,
        ]);
    }

    public function test_store_creates_prestataire_with_raison_sociale(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('prestataires.store'), [
                'raison_sociale' => 'Entreprise ABC',
                'type' => 'consultant',
                'is_active' => true,
            ])
            ->assertRedirect(route('prestataires.index'));

        $this->assertDatabaseHas('prestataires', [
            'organization_id' => $org->id,
        ]);
    }

    public function test_store_fails_without_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('prestataires.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_store_fails_without_nom_and_raison_sociale(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('prestataires.store'), [
                'type' => 'fournisseur',
            ])
            ->assertSessionHasErrors();
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);

        $this->actingAs($user)
            ->get(route('prestataires.edit', $prestataire))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $prestataire = $this->makePrestataire($otherOrg);

        $this->actingAs($user)
            ->get(route('prestataires.edit', $prestataire))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_prestataire_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);

        $this->actingAs($user)
            ->put(route('prestataires.update', $prestataire), [
                'nom' => 'Barry',
                'prenom' => 'Fatoumata',
                'type' => 'mecanicien',
                'is_active' => true,
            ])
            ->assertRedirect(route('prestataires.edit', $prestataire));

        $this->assertDatabaseHas('prestataires', [
            'id' => $prestataire->id,
        ]);
    }

    public function test_update_fails_without_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);

        $this->actingAs($user)
            ->put(route('prestataires.update', $prestataire), [
                'nom' => 'Barry',
                'prenom' => 'Fatoumata',
            ])
            ->assertSessionHasErrors('type');
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_prestataire_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);

        $this->actingAs($user)
            ->delete(route('prestataires.destroy', $prestataire))
            ->assertRedirect(route('prestataires.index'));

        $this->assertSoftDeleted('prestataires', ['id' => $prestataire->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $prestataire = $this->makePrestataire($otherOrg);

        $this->actingAs($user)
            ->delete(route('prestataires.destroy', $prestataire))
            ->assertStatus(403);
    }
}
