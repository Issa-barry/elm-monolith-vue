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

/**
 * Types de dépense — module Dépenses (déménagé des Paramètres le 2026-08-24,
 * cf. redirection de l'ancienne URL testée ci-dessous).
 */
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

        $this->get('/backoffice/depenses/types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Types/Index')
                ->has('types', 1)
                ->has('categories')
            );
    }

    public function test_index_does_not_return_other_org_types(): void
    {
        DepenseType::factory()->create(['organization_id' => $this->org->id]);
        DepenseType::factory()->create(); // autre org

        $this->get('/backoffice/depenses/types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('types', 1));
    }

    public function test_index_forbidden_sans_permission(): void
    {
        $other = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($other)
            ->get('/backoffice/depenses/types')
            ->assertForbidden();
    }

    // ── Ancienne URL Paramètres → redirection ───────────────────────────────

    public function test_old_settings_url_redirects_to_new_location(): void
    {
        $this->get('/settings/depense-types')
            ->assertRedirect('/backoffice/depenses/types');
    }

    // ── Store ────────────────────────────────────────────────────────────────

    public function test_store_creates_type_without_providing_code(): void
    {
        $this->post('/backoffice/depenses/types', [
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
        $this->post('/backoffice/depenses/types', [
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

        $this->post('/backoffice/depenses/types', [
            'libelle' => 'Carburant',
            'categorie' => CategorieDepense::VEHICULE->value,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'code' => 'carburant_2',
        ]);
    }

    public function test_store_generates_new_code_when_matching_code_is_soft_deleted(): void
    {
        $type = DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant',
            'code' => 'carburant',
        ]);
        $type->delete();

        $this->post('/backoffice/depenses/types', [
            'libelle' => 'Carburant',
            'categorie' => CategorieDepense::VEHICULE->value,
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('depense_types', [
            'organization_id' => $this->org->id,
            'code' => 'carburant_2',
            'deleted_at' => null,
        ]);
    }

    public function test_store_requires_libelle(): void
    {
        $this->post('/backoffice/depenses/types', [
            'categorie' => CategorieDepense::VEHICULE->value,
            'is_active' => true,
        ])->assertSessionHasErrors(['libelle']);
    }

    public function test_store_requires_categorie(): void
    {
        $this->post('/backoffice/depenses/types', [
            'libelle' => 'Test',
            'is_active' => true,
        ])->assertSessionHasErrors(['categorie']);
    }

    public function test_store_rejects_invalid_categorie(): void
    {
        $this->post('/backoffice/depenses/types', [
            'libelle' => 'Test',
            'categorie' => 'invalid_categorie',
            'is_active' => true,
        ])->assertSessionHasErrors(['categorie']);
    }

    public function test_store_forbidden_sans_permission_gestion(): void
    {
        $readOnly = $this->makeUserWithPermissions($this->org, ['parametres.read']);

        $this->actingAs($readOnly)
            ->post('/backoffice/depenses/types', [
                'libelle' => 'Test',
                'categorie' => CategorieDepense::INTERNE->value,
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    // ── Update ───────────────────────────────────────────────────────────────

    public function test_update_modifies_type(): void
    {
        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Ancien libellé',
        ]);

        $this->put("/backoffice/depenses/types/{$type->id}", [
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

        $this->put("/backoffice/depenses/types/{$type->id}", [
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

        $this->put("/backoffice/depenses/types/{$otherType->id}", [
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

        $this->patch("/backoffice/depenses/types/{$type->id}/toggle")
            ->assertRedirect();

        $this->assertDatabaseHas('depense_types', ['id' => $type->id, 'is_active' => false]);
    }

    // ── Destroy ──────────────────────────────────────────────────────────────

    public function test_destroy_deletes_unused_type(): void
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);

        $this->delete("/backoffice/depenses/types/{$type->id}")->assertRedirect();

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

        $this->delete("/backoffice/depenses/types/{$type->id}")
            ->assertSessionHasErrors(['delete']);

        $this->assertDatabaseHas('depense_types', ['id' => $type->id, 'deleted_at' => null]);
    }

    // ── Filtrage par concerné ────────────────────────────────────────────────

    public function test_index_returns_categorie_options_for_concerne_selector(): void
    {
        $this->get('/backoffice/depenses/types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories')
                ->where('categories.0.value', CategorieDepense::VEHICULE->value)
                ->where('categories.1.value', CategorieDepense::PROPRIETAIRE->value)
            );
    }

    public function test_types_per_concerne_are_independent(): void
    {
        DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id, 'libelle' => 'Carburant']);
        DepenseType::factory()->employe()->create(['organization_id' => $this->org->id, 'libelle' => 'Avance salaire']);
        DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Électricité']);

        $this->get('/backoffice/depenses/types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('types', 3));
    }

    // ── Export Excel ─────────────────────────────────────────────────────────

    public function test_export_excel_returns_xlsx_file(): void
    {
        DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Électricité']);

        $response = $this->get('/backoffice/depenses/types/export/excel');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type')
        );
    }

    public function test_export_excel_respects_categorie_filter(): void
    {
        DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Électricité']);
        DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id, 'libelle' => 'Carburant']);

        $this->get('/backoffice/depenses/types/export/excel?categorie='.CategorieDepense::VEHICULE->value)
            ->assertOk();
    }

    public function test_export_excel_forbidden_sans_permission_gestion(): void
    {
        $readOnly = $this->makeUserWithPermissions($this->org, ['parametres.read']);

        $this->actingAs($readOnly)
            ->get('/backoffice/depenses/types/export/excel')
            ->assertForbidden();
    }

    // ── Export PDF ───────────────────────────────────────────────────────────

    public function test_export_pdf_returns_pdf_file(): void
    {
        DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Électricité']);

        $response = $this->get('/backoffice/depenses/types/export/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_export_pdf_forbidden_sans_permission_gestion(): void
    {
        $readOnly = $this->makeUserWithPermissions($this->org, ['parametres.read']);

        $this->actingAs($readOnly)
            ->get('/backoffice/depenses/types/export/pdf')
            ->assertForbidden();
    }

    // ── Modèle d'import ──────────────────────────────────────────────────────

    public function test_import_template_downloads_xlsx(): void
    {
        $response = $this->get('/backoffice/depenses/types/import/modele');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type')
        );
    }

    public function test_import_template_forbidden_sans_permission_gestion(): void
    {
        $readOnly = $this->makeUserWithPermissions($this->org, ['parametres.read']);

        $this->actingAs($readOnly)
            ->get('/backoffice/depenses/types/import/modele')
            ->assertForbidden();
    }
}
