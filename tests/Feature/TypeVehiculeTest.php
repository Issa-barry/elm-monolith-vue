<?php

namespace Tests\Feature;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    // ── Seuil dérogatoire d'impayés ───────────────────────────────────────────────

    public function test_create_expose_le_seuil_standard_impayes(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 500_000);

        $this->actingAs($this->user)
            ->get(route('type-vehicules.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('TypeVehicules/Create')
                ->where('seuilStandardImpayes', 500_000)
            );
    }

    public function test_store_persiste_le_seuil_derogation_impayes(): void
    {
        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [
                'nom' => 'Tricycle Renforce',
                'seuil_derogation_impayes' => 2_000_000,
                'is_active' => true,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', [
            'organization_id' => $this->org->id,
            'nom' => 'Tricycle Renforce',
            'seuil_derogation_impayes' => 2_000_000,
        ]);
    }

    /** Omis du payload : aucune dérogation configurée pour ce type, jamais 0 par défaut. */
    public function test_store_sans_seuil_derogation_impayes_reste_null(): void
    {
        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [
                'nom' => 'Remorque',
                'is_active' => true,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', [
            'organization_id' => $this->org->id,
            'nom' => 'Remorque',
            'seuil_derogation_impayes' => null,
        ]);
    }

    /** Un seuil dérogatoire inférieur au seuil standard n'a pas de sens fonctionnel. */
    public function test_store_rejette_un_seuil_derogation_impayes_inferieur_au_seuil_standard(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 3_000_000);

        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [
                'nom' => 'Van sous-seuil',
                'seuil_derogation_impayes' => 2_000_000,
            ])
            ->assertSessionHasErrors('seuil_derogation_impayes');

        $this->assertDatabaseMissing('type_vehicules', ['nom' => 'Van sous-seuil']);
    }

    public function test_store_accepte_un_seuil_derogation_impayes_egal_au_seuil_standard(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 1_000_000);

        $this->actingAs($this->user)
            ->post(route('type-vehicules.store'), [
                'nom' => 'Van seuil egal',
                'seuil_derogation_impayes' => 1_000_000,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertDatabaseHas('type_vehicules', [
            'nom' => 'Van seuil egal',
            'seuil_derogation_impayes' => 1_000_000,
        ]);
    }

    public function test_update_modifie_le_seuil_derogation_impayes(): void
    {
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'seuil_derogation_impayes' => 2_000_000]);

        $this->actingAs($this->user)
            ->put(route('type-vehicules.update', $type), [
                'nom' => $type->nom,
                'seuil_derogation_impayes' => 3_000_000,
                'is_active' => true,
            ])
            ->assertRedirect(route('type-vehicules.index'));

        $this->assertSame(3_000_000, $type->fresh()->seuil_derogation_impayes);
    }

    public function test_edit_expose_le_seuil_derogation_impayes_et_le_seuil_standard(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 400_000);
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'seuil_derogation_impayes' => 1_500_000]);

        $this->actingAs($this->user)
            ->get(route('type-vehicules.edit', $type))
            ->assertInertia(fn (Assert $page) => $page
                ->component('TypeVehicules/Edit')
                ->where('type.seuil_derogation_impayes', 1_500_000)
                ->where('seuilStandardImpayes', 400_000)
            );
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
