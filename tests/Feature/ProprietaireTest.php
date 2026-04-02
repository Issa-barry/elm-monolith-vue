<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class ProprietaireTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private function user(): User
    {
        return $this->makeAdminUser();
    }

    private function userWithPermissions(Organization $org): User
    {
        return $this->makeUserWithPermissions($org, ['proprietaires.read', 'proprietaires.create', 'proprietaires.update', 'proprietaires.delete']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('proprietaires.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('proprietaires.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('proprietaires.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('proprietaires.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_proprietaire_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), [
                'nom' => 'Camara',
                'prenom' => 'Ibrahima',
                'is_active' => true,
            ])
            ->assertRedirect(route('proprietaires.index'));

        $this->assertDatabaseHas('proprietaires', [
            'nom' => 'CAMARA',
            'organization_id' => $org->id,
        ]);
    }

    public function test_store_fails_with_missing_nom_and_prenom(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), [])
            ->assertSessionHasErrors(['nom', 'prenom']);
    }

    public function test_store_accepts_optional_telephone(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), [
                'nom' => 'Sylla',
                'prenom' => 'Kadiatou',
                'telephone' => '622000003',
                'code_pays' => 'GN',
                'is_active' => true,
            ])
            ->assertRedirect(route('proprietaires.index'));

        $this->assertDatabaseHas('proprietaires', [
            'organization_id' => $org->id,
        ]);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('proprietaires.edit', $proprietaire))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->get(route('proprietaires.edit', $proprietaire))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_proprietaire_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), [
                'nom' => 'Balde',
                'prenom' => 'Thierno',
                'is_active' => true,
            ])
            ->assertRedirect(route('proprietaires.edit', $proprietaire));

        $this->assertDatabaseHas('proprietaires', [
            'id' => $proprietaire->id,
            'nom' => 'BALDE',
        ]);
    }

    public function test_update_fails_with_missing_nom_and_prenom(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), [])
            ->assertSessionHasErrors(['nom', 'prenom']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_proprietaire_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->delete(route('proprietaires.destroy', $proprietaire))
            ->assertRedirect(route('proprietaires.index'));

        $this->assertSoftDeleted('proprietaires', ['id' => $proprietaire->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->delete(route('proprietaires.destroy', $proprietaire))
            ->assertStatus(403);
    }
}
