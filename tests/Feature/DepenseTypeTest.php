<?php

namespace Tests\Feature;

use App\Enums\CategorieDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class DepenseTypeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['parametres.read', 'parametres.update']);
        $this->actingAs($this->user);
    }

    // ── Index ────────────────────────────────────────────────────────────────

    public function test_index_renders_with_types_and_categories(): void
    {
        DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Restauration',
            'code' => 'bouffe',
        ]);

        $this->get('/settings/depense-types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/DepenseTypes/Index')
                ->has('types', 1)
                ->has('categories')
            );
    }

    public function test_index_does_not_return_other_org_types(): void
    {
        DepenseType::factory()->create(['organization_id' => $this->org->id]);
        DepenseType::factory()->create(); // autre org

        $this->get('/settings/depense-types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('types', 1));
    }

    // ── Store ────────────────────────────────────────────────────────────────

    public function test_store_creates_type_without_providing_code(): void
    {
        $this->post('/settings/depense-types', [
            'libelle' => 'Carburant',
            'categorie' => CategorieDepense::VEHICULE->value,
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant',
            'categorie' => CategorieDepense::VEHICULE->value,
        ]);
    }

    public function test_store_generates_code_from_libelle(): void
    {
        $this->post('/settings/depense-types', [
            'libelle' => 'Achat pneu',
            'categorie' => CategorieDepense::VEHICULE->value,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'code' => 'achat_pneu',
        ]);
    }

    public function test_store_handles_duplicate_code_with_suffix(): void
    {
        DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant',
            'code' => 'carburant',
        ]);

        $this->post('/settings/depense-types', [
            'libelle' => 'Carburant',
            'categorie' => CategorieDepense::VEHICULE->value,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'code' => 'carburant_2',
        ]);
    }

    public function test_store_requires_libelle(): void
    {
        $this->post('/settings/depense-types', [
            'categorie' => CategorieDepense::VEHICULE->value,
            'is_active' => true,
        ])->assertSessionHasErrors(['libelle']);
    }

    public function test_store_requires_categorie(): void
    {
        $this->post('/settings/depense-types', [
            'libelle' => 'Test',
            'is_active' => true,
        ])->assertSessionHasErrors(['categorie']);
    }

    public function test_store_rejects_invalid_categorie(): void
    {
        $this->post('/settings/depense-types', [
            'libelle' => 'Test',
            'categorie' => 'invalid_categorie',
            'is_active' => true,
        ])->assertSessionHasErrors(['categorie']);
    }

    // ── Update ───────────────────────────────────────────────────────────────

    public function test_update_modifies_type(): void
    {
        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Ancien libellé',
        ]);

        $this->put("/settings/depense-types/{$type->id}", [
            'libelle' => 'Nouveau libellé',
            'categorie' => CategorieDepense::EMPLOYE->value,
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('depense_types', [
            'id' => $type->id,
            'libelle' => 'Nouveau libellé',
            'categorie' => CategorieDepense::EMPLOYE->value,
            'commentaire_obligatoire' => true,
        ]);
    }

    public function test_update_does_not_change_code(): void
    {
        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'code' => 'code_original',
            'libelle' => 'Ancien libellé',
        ]);

        $this->put("/settings/depense-types/{$type->id}", [
            'libelle' => 'Nouveau libellé',
            'categorie' => CategorieDepense::INTERNE->value,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('depense_types', [
            'id' => $type->id,
            'code' => 'code_original',
        ]);
    }

    public function test_update_cannot_touch_other_org_type(): void
    {
        $otherType = DepenseType::factory()->interne()->create();

        $this->put("/settings/depense-types/{$otherType->id}", [
            'libelle' => 'Hack',
            'categorie' => CategorieDepense::INTERNE->value,
            'is_active' => true,
        ])->assertForbidden();
    }

    // ── Toggle ───────────────────────────────────────────────────────────────

    public function test_toggle_flips_is_active(): void
    {
        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $this->patch("/settings/depense-types/{$type->id}/toggle")
            ->assertRedirect();

        $this->assertDatabaseHas('depense_types', ['id' => $type->id, 'is_active' => false]);
    }

    // ── Destroy ──────────────────────────────────────────────────────────────

    public function test_destroy_deletes_unused_type(): void
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);

        $this->delete("/settings/depense-types/{$type->id}")->assertRedirect();

        $this->assertSoftDeleted('depense_types', ['id' => $type->id]);
    }

    public function test_destroy_blocked_when_type_has_depenses(): void
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
        ]);

        $this->delete("/settings/depense-types/{$type->id}")
            ->assertSessionHasErrors(['delete']);

        $this->assertDatabaseHas('depense_types', ['id' => $type->id, 'deleted_at' => null]);
    }

    // ── Filtrage par concerné ────────────────────────────────────────────────

    public function test_index_returns_categorie_options_for_concerne_selector(): void
    {
        $this->get('/settings/depense-types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories')
                ->where('categories.0.value', CategorieDepense::VEHICULE->value)
                ->where('categories.1.value', CategorieDepense::PROPRIETAIRE->value)
            );
    }

    public function test_store_creates_type_vehicule_correctly(): void
    {
        $this->post('/settings/depense-types', [
            'libelle' => 'Carburant véhicule',
            'categorie' => CategorieDepense::VEHICULE->value,
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'categorie' => CategorieDepense::VEHICULE->value,
            'libelle' => 'Carburant véhicule',
        ]);
    }

    public function test_types_per_concerne_are_independent(): void
    {
        DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id, 'libelle' => 'Carburant']);
        DepenseType::factory()->employe()->create(['organization_id' => $this->org->id, 'libelle' => 'Avance salaire']);
        DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Électricité']);

        $this->get('/settings/depense-types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('types', 3));
    }
}
