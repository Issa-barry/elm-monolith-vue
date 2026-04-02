<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private function user(): User
    {
        return $this->makeAdminUser();
    }

    private function userWithPermissions(Organization $org): User
    {
        return $this->makeUserWithPermissions($org, ['sites.read', 'sites.create', 'sites.update', 'sites.delete']);
    }

    private function makeSite(Organization $org): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry, Guinée',
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('sites.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('sites.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('sites.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('sites.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_site_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('sites.store'), [
                'nom' => 'Depot Conakry',
                'type' => 'depot',
                'localisation' => 'Ratoma, Conakry',
                'ville' => 'Conakry',
                'pays' => 'Guinée',
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'organization_id' => $org->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('sites.store'), [])
            ->assertSessionHasErrors(['nom', 'type', 'localisation']);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('sites.store'), [
                'nom' => 'Test',
                'type' => 'type_invalide',
                'localisation' => 'Quelque part',
            ])
            ->assertSessionHasErrors('type');
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $site = $this->makeSite($org);

        $this->actingAs($user)
            ->get(route('sites.show', $site))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $site = $this->makeSite($otherOrg);

        $this->actingAs($user)
            ->get(route('sites.show', $site))
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $site = $this->makeSite($org);

        $this->actingAs($user)
            ->get(route('sites.edit', $site))
            ->assertStatus(200);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_site_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $site = $this->makeSite($org);

        $this->actingAs($user)
            ->put(route('sites.update', $site), [
                'nom' => 'Depot modifie',
                'code' => $site->code,
                'type' => 'depot',
                'localisation' => 'Kaloum, Conakry',
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $site = $this->makeSite($org);

        $this->actingAs($user)
            ->put(route('sites.update', $site), [])
            ->assertSessionHasErrors(['nom', 'code', 'type', 'localisation']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_site_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $site = $this->makeSite($org);

        $this->actingAs($user)
            ->delete(route('sites.destroy', $site))
            ->assertRedirect(route('sites.index'));

        $this->assertSoftDeleted('sites', ['id' => $site->id]);
    }

    public function test_destroy_returns_back_if_site_has_children(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $parent = $this->makeSite($org);

        Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site enfant',
            'type' => 'agence',
            'localisation' => 'Quelque part',
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($user)
            ->delete(route('sites.destroy', $parent))
            ->assertRedirect();

        $this->assertDatabaseHas('sites', ['id' => $parent->id, 'deleted_at' => null]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $site = $this->makeSite($otherOrg);

        $this->actingAs($user)
            ->delete(route('sites.destroy', $site))
            ->assertStatus(403);
    }

    // ── show with children ────────────────────────────────────────────────────

    public function test_show_displays_children_sites(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $parent = $this->makeSite($org);
        $child = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Enfant',
            'type' => 'agence',
            'localisation' => 'Kindia',
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($user)
            ->get(route('sites.show', $parent))
            ->assertStatus(200);
    }
}
