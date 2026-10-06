<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['sites.read', 'sites.create', 'sites.update', 'sites.delete']);
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
        $this->actingAs($this->user)
            ->get(route('sites.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('sites.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('sites.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.create'))
            ->assertStatus(200);
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('sites.create'))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->post(route('sites.store'), [
                'nom' => 'Depot Conakry',
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Ratoma',
            ])
            ->assertStatus(403);
    }

    public function test_store_creates_site_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('sites.store'), [
                'nom' => 'Depot Conakry',
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Ratoma',
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('sites.store'), [])
            ->assertSessionHasErrors(['nom', 'type']);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('sites.store'), [
                'nom' => 'Test',
                'type' => 'type_invalide',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_store_defaults_commissions_active_to_true_when_omitted(): void
    {
        $this->actingAs($this->user)
            ->post(route('sites.store'), [
                'nom' => 'Depot Conakry',
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Ratoma',
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'organization_id' => $this->org->id,
            'commissions_active' => true,
        ]);
    }

    public function test_store_persists_commissions_active_false(): void
    {
        $this->actingAs($this->user)
            ->post(route('sites.store'), [
                'nom' => 'Depot Conakry',
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Ratoma',
                'commissions_active' => false,
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'organization_id' => $this->org->id,
            'commissions_active' => false,
        ]);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $site = $this->makeSite($this->org);

        $this->actingAs($this->user)
            ->get(route('sites.show', $site))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $site = $this->makeSite($otherOrg);

        $this->actingAs($this->user)
            ->get(route('sites.show', $site))
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $site = $this->makeSite($this->org);

        $this->actingAs($this->user)
            ->get(route('sites.edit', $site))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $site = $this->makeSite($otherOrg);

        $this->actingAs($this->user)
            ->get(route('sites.edit', $site))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $site = $this->makeSite($otherOrg);

        $this->actingAs($this->user)
            ->put(route('sites.update', $site), [
                'nom' => 'Depot modifie',
                'code' => $site->code,
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Kaloum',
            ])
            ->assertStatus(403);
    }

    public function test_update_modifies_site_and_redirects(): void
    {
        $site = $this->makeSite($this->org);

        $this->actingAs($this->user)
            ->put(route('sites.update', $site), [
                'nom' => 'Depot modifie',
                'code' => $site->code,
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Kaloum',
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
        ]);
    }

    public function test_update_persists_commissions_active_false(): void
    {
        $site = $this->makeSite($this->org);
        // ->fresh() : commissions_active n'est posé qu'au niveau du défaut colonne (SQL), jamais
        // renseigné sur l'instance en mémoire retournée par Site::create() tant qu'elle n'a pas
        // été relue depuis la base.
        $this->assertTrue($site->fresh()->commissions_active, 'défaut attendu à la création');

        $this->actingAs($this->user)
            ->put(route('sites.update', $site), [
                'nom' => $site->nom,
                'code' => $site->code,
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Kaloum',
                'commissions_active' => false,
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'commissions_active' => false]);
    }

    public function test_update_without_commissions_active_field_leaves_value_unchanged(): void
    {
        $site = $this->makeSite($this->org);
        $site->update(['commissions_active' => false]);

        $this->actingAs($this->user)
            ->put(route('sites.update', $site), [
                'nom' => $site->nom,
                'code' => $site->code,
                'type' => 'depot',
                'ville' => 'Conakry',
                'quartier' => 'Kaloum',
                // commissions_active volontairement omis : un appel API qui ignore ce champ ne
                // doit jamais réactiver silencieusement les commissions d'un site désactivé.
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'commissions_active' => false]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $site = $this->makeSite($this->org);

        $this->actingAs($this->user)
            ->put(route('sites.update', $site), [])
            ->assertSessionHasErrors(['nom', 'code', 'type']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_site_and_redirects(): void
    {
        $site = $this->makeSite($this->org);

        $this->actingAs($this->user)
            ->delete(route('sites.destroy', $site))
            ->assertRedirect(route('sites.index'));

        $this->assertSoftDeleted('sites', ['id' => $site->id]);
    }

    public function test_destroy_returns_back_if_site_has_children(): void
    {
        $parent = $this->makeSite($this->org);

        Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site enfant',
            'type' => 'agence',
            'localisation' => 'Quelque part',
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->user)
            ->delete(route('sites.destroy', $parent))
            ->assertRedirect();

        $this->assertDatabaseHas('sites', ['id' => $parent->id, 'deleted_at' => null]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $site = $this->makeSite($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('sites.destroy', $site))
            ->assertStatus(403);
    }

    // ── show with children ────────────────────────────────────────────────────

    public function test_show_displays_children_sites(): void
    {
        $parent = $this->makeSite($this->org);
        Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Enfant',
            'type' => 'agence',
            'localisation' => 'Kindia',
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('sites.show', $parent))
            ->assertStatus(200);
    }
}
