<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Prestataire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class PrestataireTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['prestataires.read', 'prestataires.create', 'prestataires.update', 'prestataires.delete']);
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'phone' => '622000001',
            'code_pays' => 'GN',
            'ville' => 'Conakry',
            'type' => 'fournisseur',
            'is_active' => true,
        ], $overrides);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('prestataires.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('prestataires.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('prestataires.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('prestataires.create'))
            ->assertStatus(200);
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('prestataires.create'))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_prestataire_with_nom_prenom_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('prestataires.store'), $this->validPayload())
            ->assertRedirect(route('prestataires.index'));

        $this->assertDatabaseHas('prestataires', [
            'organization_id' => $this->org->id,
            'nom' => 'DIALLO',
        ]);
    }

    public function test_store_creates_prestataire_with_raison_sociale_only(): void
    {
        $this->actingAs($this->user)
            ->post(route('prestataires.store'), $this->validPayload([
                'nom' => null,
                'prenom' => null,
                'raison_sociale' => 'Entreprise ABC',
            ]))
            ->assertRedirect(route('prestataires.index'));

        $this->assertDatabaseHas('prestataires', [
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_fails_without_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('prestataires.store'), $this->validPayload(['type' => null]))
            ->assertSessionHasErrors('type');
    }

    public function test_store_fails_without_nom_and_raison_sociale(): void
    {
        $this->actingAs($this->user)
            ->post(route('prestataires.store'), $this->validPayload([
                'nom' => null,
                'prenom' => null,
            ]))
            ->assertSessionHasErrors(['nom', 'prenom']);
    }

    public function test_store_fails_when_only_nom_without_prenom(): void
    {
        $this->actingAs($this->user)
            ->post(route('prestataires.store'), $this->validPayload(['prenom' => null]))
            ->assertSessionHasErrors('prenom');
    }

    public function test_store_fails_with_missing_required_location_and_phone(): void
    {
        $this->actingAs($this->user)
            ->post(route('prestataires.store'), $this->validPayload([
                'phone' => null,
                'code_pays' => null,
                'ville' => null,
            ]))
            ->assertSessionHasErrors(['phone', 'code_pays', 'ville']);
    }

    public function test_store_fails_with_invalid_code_pays(): void
    {
        $this->actingAs($this->user)
            ->post(route('prestataires.store'), $this->validPayload(['code_pays' => 'XX']))
            ->assertSessionHasErrors('code_pays');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $prestataire = $this->makePrestataire($this->org);

        $this->actingAs($this->user)
            ->get(route('prestataires.edit', $prestataire))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $prestataire = $this->makePrestataire($otherOrg);

        $this->actingAs($this->user)
            ->get(route('prestataires.edit', $prestataire))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_prestataire_and_redirects(): void
    {
        $prestataire = $this->makePrestataire($this->org);

        $this->actingAs($this->user)
            ->put(route('prestataires.update', $prestataire), $this->validPayload([
                'nom' => 'Barry',
                'prenom' => 'Fatoumata',
                'type' => 'mecanicien',
            ]))
            ->assertRedirect(route('prestataires.edit', $prestataire));

        $this->assertDatabaseHas('prestataires', [
            'id' => $prestataire->id,
            'nom' => 'BARRY',
        ]);
    }

    public function test_update_with_raison_sociale_only(): void
    {
        $prestataire = $this->makePrestataire($this->org);

        $this->actingAs($this->user)
            ->put(route('prestataires.update', $prestataire), $this->validPayload([
                'nom' => null,
                'prenom' => null,
                'raison_sociale' => 'SARL Nouvelle',
                'type' => 'consultant',
            ]))
            ->assertRedirect(route('prestataires.edit', $prestataire));

        $this->assertDatabaseHas('prestataires', [
            'id' => $prestataire->id,
        ]);
    }

    public function test_update_fails_without_type(): void
    {
        $prestataire = $this->makePrestataire($this->org);

        $this->actingAs($this->user)
            ->put(route('prestataires.update', $prestataire), $this->validPayload(['type' => null]))
            ->assertSessionHasErrors('type');
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $prestataire = $this->makePrestataire($this->org);

        $this->actingAs($this->user)
            ->put(route('prestataires.update', $prestataire), [])
            ->assertSessionHasErrors(['phone', 'code_pays', 'ville', 'type']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_prestataire_and_redirects(): void
    {
        $prestataire = $this->makePrestataire($this->org);

        $this->actingAs($this->user)
            ->delete(route('prestataires.destroy', $prestataire))
            ->assertRedirect(route('prestataires.index'));

        $this->assertSoftDeleted('prestataires', ['id' => $prestataire->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $prestataire = $this->makePrestataire($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('prestataires.destroy', $prestataire))
            ->assertStatus(403);
    }
}
