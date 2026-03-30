<?php

namespace Tests\Feature;

use App\Enums\PackingStatut;
use App\Models\Organization;
use App\Models\Packing;
use App\Models\Prestataire;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VersementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'packings.update', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('packings.update');

        return $user;
    }

    private function makePacking(Organization $org, array $overrides = []): Packing
    {
        $prestataire = Prestataire::create([
            'organization_id' => $org->id,
            'nom' => 'FOURNISSEUR TEST',
            'type' => 'fournisseur',
            'is_active' => true,
        ]);

        return Packing::create(array_merge([
            'organization_id' => $org->id,
            'prestataire_id' => $prestataire->id,
            'date' => now()->toDateString(),
            'nb_rouleaux' => 10,
            'prix_par_rouleau' => 1000,
            'statut' => PackingStatut::IMPAYEE,
        ], $overrides));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_versement_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $packing = $this->makePacking($org);

        $this->actingAs($user)
            ->post(route('packings.versements.store', $packing), [
                'date' => now()->toDateString(),
                'montant' => 5000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('versements', [
            'packing_id' => $packing->id,
            'montant' => 5000,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $packing = $this->makePacking($org);

        $this->actingAs($user)
            ->post(route('packings.versements.store', $packing), [])
            ->assertSessionHasErrors(['date', 'montant']);
    }

    public function test_store_fails_if_montant_exceeds_restant(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $packing = $this->makePacking($org);

        $this->actingAs($user)
            ->post(route('packings.versements.store', $packing), [
                'date' => now()->toDateString(),
                'montant' => 99999999,
            ])
            ->assertSessionHasErrors('montant');
    }

    public function test_store_fails_if_packing_is_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $packing = $this->makePacking($org, ['statut' => PackingStatut::ANNULEE]);

        $this->actingAs($user)
            ->post(route('packings.versements.store', $packing), [
                'date' => now()->toDateString(),
                'montant' => 1000,
            ])
            ->assertStatus(403);
    }

    public function test_store_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $packing = $this->makePacking($otherOrg);

        $this->actingAs($user)
            ->post(route('packings.versements.store', $packing), [
                'date' => now()->toDateString(),
                'montant' => 1000,
            ])
            ->assertStatus(403);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_versement_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $packing = $this->makePacking($org);

        $versement = $packing->versements()->create([
            'date' => now()->toDateString(),
            'montant' => 3000,
        ]);

        $this->actingAs($user)
            ->delete(route('packings.versements.destroy', [$packing, $versement]))
            ->assertRedirect();

        $this->assertDatabaseMissing('versements', ['id' => $versement->id]);
    }

    public function test_destroy_fails_if_packing_is_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $packing = $this->makePacking($org, ['statut' => PackingStatut::ANNULEE]);

        $versement = Versement::create([
            'packing_id' => $packing->id,
            'date' => now()->toDateString(),
            'montant' => 1000,
        ]);

        $this->actingAs($user)
            ->delete(route('packings.versements.destroy', [$packing, $versement]))
            ->assertStatus(403);
    }
}
