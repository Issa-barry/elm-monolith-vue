<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Les livreurs sont gérés depuis les Équipes de livraison (API modale JSON).
 * Le LivreurController expose : index (Inertia), store (JSON 201), toggle (JSON), destroy (JSON).
 */
class LivreurTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['livreurs.read', 'livreurs.create', 'livreurs.update', 'livreurs.delete']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('livreurs.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('livreurs.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('livreurs.index'))
            ->assertStatus(403);
    }

    // ── store (JSON API) ──────────────────────────────────────────────────────

    public function test_store_creates_livreur_and_returns_json(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('livreurs.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '+224622000001',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'nom', 'prenom', 'telephone', 'is_active']);

        $this->assertDatabaseHas('livreurs', [
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('livreurs.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nom', 'prenom', 'telephone']);
    }

    public function test_store_fails_with_duplicate_telephone(): void
    {
        Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000002',
        ]);

        $this->actingAs($this->user)
            ->postJson(route('livreurs.store'), [
                'nom' => 'Barry',
                'prenom' => 'Alpha',
                'telephone' => '+224622000002',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telephone']);
    }

    // ── toggle ────────────────────────────────────────────────────────────────

    public function test_toggle_changes_active_status(): void
    {
        $livreur = Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->patchJson(route('livreurs.toggle', $livreur))
            ->assertStatus(200)
            ->assertJson(['is_active' => false]);

        $this->assertFalse($livreur->fresh()->is_active);
    }

    public function test_toggle_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->patchJson(route('livreurs.toggle', $livreur))
            ->assertStatus(403);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_supprime_livreur_sans_historique(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->deleteJson(route('livreurs.destroy', $livreur))
            ->assertStatus(200)
            ->assertJson(['action' => 'deleted']);

        $this->assertSoftDeleted('livreurs', ['id' => $livreur->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->deleteJson(route('livreurs.destroy', $livreur))
            ->assertStatus(403);
    }
}
