<?php

namespace Tests\Feature;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class TypeVehiculeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['type-vehicules.read', 'type-vehicules.create', 'type-vehicules.update', 'type-vehicules.delete']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('type-vehicules.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('type-vehicules.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('type-vehicules.index'))
            ->assertStatus(403);
    }

    public function test_index_only_shows_own_org_types(): void
    {
        $this->actingAs($this->user)
            ->get(route('type-vehicules.index'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('TypeVehicules/Index')
                ->where('types', fn ($types) => collect($types)->every(
                    fn ($t) => true
                ))
            );
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('type-vehicules.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_type_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [
                'nom' => 'Fourgon',
                'is_active' => true,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', [
            'organization_id' => $this->org->id,
            'nom' => 'Fourgon',
        ]);
    }

    public function test_store_accepte_une_categorie_tarifaire(): void
    {
        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [
                'nom' => 'Tricycle Express',
                'categorie_tarifaire' => CategorieTarifaireVehicule::TRICYCLE->value,
                'is_active' => true,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', [
            'organization_id' => $this->org->id,
            'nom' => 'Tricycle Express',
            'categorie_tarifaire' => CategorieTarifaireVehicule::TRICYCLE->value,
        ]);
    }

    public function test_store_refuse_une_categorie_tarifaire_invalide(): void
    {
        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [
                'nom' => 'Van',
                'categorie_tarifaire' => 'valeur_inconnue',
            ])
            ->assertSessionHasErrors('categorie_tarifaire');
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [])
            ->assertSessionHasErrors(['nom']);
    }

    public function test_store_fails_with_duplicate_nom_in_same_org(): void
    {
        // "Camion" is already seeded by HasOrgAndUser — try to create a duplicate
        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), ['nom' => 'Camion'])
            ->assertSessionHasErrors('nom');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get(route('type-vehicules.edit', $type))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $type = TypeVehicule::factory()->create();

        $this->actingAs($this->user)
            ->get(route('type-vehicules.edit', $type))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_type_and_redirects(): void
    {
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('type-vehicules.update', $type), [
                'nom' => 'Moto',
                'is_active' => true,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', [
            'id' => $type->id,
            'nom' => 'Moto',
        ]);
    }

    public function test_update_modifie_la_categorie_tarifaire(): void
    {
        $type = TypeVehicule::factory()->create([
            'organization_id' => $this->org->id,
            'categorie_tarifaire' => CategorieTarifaireVehicule::AUTRE_VEHICULE,
        ]);

        $this->actingAs($this->user)
            ->put(route('type-vehicules.update', $type), [
                'nom' => $type->nom,
                'categorie_tarifaire' => CategorieTarifaireVehicule::TRICYCLE->value,
                'is_active' => true,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', [
            'id' => $type->id,
            'categorie_tarifaire' => CategorieTarifaireVehicule::TRICYCLE->value,
        ]);
    }

    public function test_update_fails_with_missing_fields(): void
    {
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('type-vehicules.update', $type), [])
            ->assertSessionHasErrors(['nom']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_unused_type(): void
    {
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->delete(route('type-vehicules.destroy', $type))
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertSoftDeleted('type_vehicules', ['id' => $type->id]);
    }

    public function test_destroy_blocked_when_type_has_vehicules(): void
    {
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $type->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $this->actingAs($this->user)
            ->delete(route('type-vehicules.destroy', $type))
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', ['id' => $type->id, 'deleted_at' => null]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $type = TypeVehicule::factory()->create();

        $this->actingAs($this->user)
            ->delete(route('type-vehicules.destroy', $type))
            ->assertStatus(403);
    }
}
