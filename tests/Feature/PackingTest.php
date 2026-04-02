<?php

namespace Tests\Feature;

use App\Enums\PackingShift;
use App\Enums\PackingStatut;
use App\Models\Organization;
use App\Models\Packing;
use App\Models\Prestataire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class PackingTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private function user(): User
    {
        return $this->makeAdminUser();
    }

    private function userWithPermissions(Organization $org): User
    {
        return $this->makeUserWithPermissions($org, ['packings.read', 'packings.create', 'packings.update', 'packings.delete']);
    }

    private function makePrestataire(Organization $org): Prestataire
    {
        return Prestataire::create([
            'organization_id' => $org->id,
            'nom' => 'FOURNISSEUR TEST',
            'type' => 'fournisseur',
            'is_active' => true,
        ]);
    }

    private function makePacking(Organization $org, Prestataire $prestataire, array $overrides = []): Packing
    {
        return Packing::create(array_merge([
            'organization_id' => $org->id,
            'prestataire_id' => $prestataire->id,
            'date' => now()->toDateString(),
            'nb_rouleaux' => 10,
            'prix_par_rouleau' => 5000,
            'statut' => PackingStatut::IMPAYEE,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('packings.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('packings.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('packings.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('packings.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_packing_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);

        $response = $this->actingAs($user)
            ->post(route('packings.store'), [
                'prestataire_id' => $prestataire->id,
                'date' => now()->toDateString(),
                'shift' => 'jour',
                'nb_rouleaux' => 5,
                'prix_par_rouleau' => 1000,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('packings', [
            'organization_id' => $org->id,
            'prestataire_id' => $prestataire->id,
            'nb_rouleaux' => 5,
            'shift' => 'jour',
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('packings.store'), [])
            ->assertSessionHasErrors(['prestataire_id', 'date', 'shift', 'nb_rouleaux', 'prix_par_rouleau']);
    }

    public function test_store_creates_packing_with_nuit_shift(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);

        $this->actingAs($user)
            ->post(route('packings.store'), [
                'prestataire_id' => $prestataire->id,
                'date' => now()->toDateString(),
                'shift' => 'nuit',
                'nb_rouleaux' => 3,
                'prix_par_rouleau' => 2000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('packings', [
            'organization_id' => $org->id,
            'shift' => 'nuit',
        ]);
    }

    public function test_store_fails_with_invalid_shift(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);

        $this->actingAs($user)
            ->post(route('packings.store'), [
                'prestataire_id' => $prestataire->id,
                'date' => now()->toDateString(),
                'shift' => 'matin',
                'nb_rouleaux' => 5,
                'prix_par_rouleau' => 1000,
            ])
            ->assertSessionHasErrors('shift');
    }

    public function test_store_fails_with_prestataire_from_other_org(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $prestataire = $this->makePrestataire($otherOrg);

        $this->actingAs($user)
            ->post(route('packings.store'), [
                'prestataire_id' => $prestataire->id,
                'date' => now()->toDateString(),
                'nb_rouleaux' => 5,
                'prix_par_rouleau' => 1000,
            ])
            ->assertSessionHasErrors('prestataire_id');
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire);

        $this->actingAs($user)
            ->get(route('packings.show', $packing))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $prestataire = $this->makePrestataire($otherOrg);
        $packing = $this->makePacking($otherOrg, $prestataire);

        $this->actingAs($user)
            ->get(route('packings.show', $packing))
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_impayee_packing(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire);

        $this->actingAs($user)
            ->get(route('packings.edit', $packing))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_paid_packing(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire, ['statut' => PackingStatut::PAYEE]);

        $this->actingAs($user)
            ->get(route('packings.edit', $packing))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_impayee_packing_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire);

        $this->actingAs($user)
            ->put(route('packings.update', $packing), [
                'prestataire_id' => $prestataire->id,
                'date' => now()->toDateString(),
                'shift' => 'nuit',
                'nb_rouleaux' => 20,
                'prix_par_rouleau' => 2000,
            ])
            ->assertRedirect(route('packings.show', $packing));

        $this->assertDatabaseHas('packings', [
            'id' => $packing->id,
            'nb_rouleaux' => 20,
            'montant' => 40000,
            'shift' => 'nuit',
        ]);
    }

    public function test_update_returns_403_for_paid_packing(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire, ['statut' => PackingStatut::PAYEE]);

        $this->actingAs($user)
            ->put(route('packings.update', $packing), [
                'prestataire_id' => $prestataire->id,
                'date' => now()->toDateString(),
                'shift' => 'jour',
                'nb_rouleaux' => 20,
                'prix_par_rouleau' => 2000,
            ])
            ->assertStatus(403);
    }

    public function test_update_fails_with_invalid_shift(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire);

        $this->actingAs($user)
            ->put(route('packings.update', $packing), [
                'prestataire_id' => $prestataire->id,
                'date' => now()->toDateString(),
                'shift' => 'soir',
                'nb_rouleaux' => 10,
                'prix_par_rouleau' => 1000,
            ])
            ->assertSessionHasErrors('shift');
    }

    public function test_shift_default_is_jour(): void
    {
        $org = Organization::factory()->create();
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire);

        $this->assertEquals(PackingShift::JOUR, $packing->fresh()->shift);
    }

    public function test_packingdata_includes_shift(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire, ['shift' => PackingShift::NUIT]);

        $response = $this->actingAs($user)
            ->get(route('packings.show', $packing));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Packings/Show')
            ->where('packing.shift', 'nuit')
            ->where('packing.shift_label', 'Nuit')
        );
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_impayee_packing_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire);

        $this->actingAs($user)
            ->delete(route('packings.destroy', $packing))
            ->assertRedirect(route('packings.index'));

        $this->assertSoftDeleted('packings', ['id' => $packing->id]);
    }

    public function test_destroy_returns_403_for_paid_packing(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire, ['statut' => PackingStatut::PAYEE]);

        $this->actingAs($user)
            ->delete(route('packings.destroy', $packing))
            ->assertStatus(403);
    }

    // ── annuler ───────────────────────────────────────────────────────────────

    public function test_annuler_sets_statut_to_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire);

        $this->actingAs($user)
            ->patch(route('packings.annuler', $packing))
            ->assertRedirect();

        $this->assertEquals(PackingStatut::ANNULEE, $packing->fresh()->statut);
    }

    public function test_annuler_returns_403_if_already_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $prestataire = $this->makePrestataire($org);
        $packing = $this->makePacking($org, $prestataire, ['statut' => PackingStatut::ANNULEE]);

        $this->actingAs($user)
            ->patch(route('packings.annuler', $packing))
            ->assertStatus(403);
    }
}
