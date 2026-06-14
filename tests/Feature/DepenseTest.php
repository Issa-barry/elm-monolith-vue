<?php

namespace Tests\Feature;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class DepenseTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private DepenseType $typeInterne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'depenses.read',
            'depenses.create',
            'depenses.update',
            'depenses.delete',
        ]);
        $this->actingAs($this->user);

        $this->typeInterne = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Restauration',
            'code' => 'bouffe',
        ]);
    }

    // ── Index ────────────────────────────────────────────────────────────────

    public function test_index_renders_depenses(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get('/depenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Index')
                ->has('depenses.data', 1)
                ->has('types')
                ->has('categories')
                ->has('statuts')
            );
    }

    public function test_index_does_not_return_other_org_depenses(): void
    {
        Depense::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'depense_type_id' => $this->typeInterne->id]);
        Depense::factory()->create(); // autre org

        $this->get('/depenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    // ── Create / Store ───────────────────────────────────────────────────────

    public function test_create_renders_form_with_props(): void
    {
        $this->get('/depenses/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Create')
                ->has('types')
                ->has('vehicules')
                ->has('sites')
                ->has('employes')
                ->has('livreurs')
                ->has('proprietaires')
            );
    }

    public function test_store_creates_interne_depense(): void
    {
        $this->post('/depenses', [
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 25000,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::BROUILLON->value,
        ])->assertRedirect('/depenses');

        $this->assertDatabaseHas('depenses', [
            'organization_id' => $this->org->id,
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 25000,
            'statut' => StatutDepense::BROUILLON->value,
            'beneficiaire_type' => null,
            'beneficiaire_id' => null,
        ]);
    }

    public function test_store_with_vehicule_type_requires_beneficiaire(): void
    {
        $typeVehicule = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);

        $this->post('/depenses', [
            'depense_type_id' => $typeVehicule->id,
            'montant' => 50000,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::BROUILLON->value,
            // beneficiaire_id manquant
        ])->assertSessionHasErrors(['beneficiaire_id']);
    }

    public function test_store_with_employe_type_saves_beneficiaire(): void
    {
        $typeEmploye = DepenseType::factory()->employe()->create(['organization_id' => $this->org->id]);
        $employe = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
        ]);

        $this->post('/depenses', [
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_id' => $employe->id,
            'montant' => 100000,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::BROUILLON->value,
        ])->assertRedirect('/depenses');

        $this->assertDatabaseHas('depenses', [
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $employe->id,
        ]);
    }

    public function test_store_requires_montant_positive(): void
    {
        $this->post('/depenses', [
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 0,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::BROUILLON->value,
        ])->assertSessionHasErrors(['montant']);
    }

    public function test_store_rejects_inactive_type(): void
    {
        $inactiveType = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'is_active' => false,
        ]);

        $this->post('/depenses', [
            'depense_type_id' => $inactiveType->id,
            'montant' => 10000,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::BROUILLON->value,
        ])->assertSessionHasErrors(['depense_type_id']);
    }

    public function test_store_rejects_type_from_other_org(): void
    {
        $otherOrgType = DepenseType::factory()->interne()->create(); // autre org

        $this->post('/depenses', [
            'depense_type_id' => $otherOrgType->id,
            'montant' => 10000,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::BROUILLON->value,
        ])->assertSessionHasErrors(['depense_type_id']);
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    public function test_show_renders_depense_detail(): void
    {
        $depense = Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get("/depenses/{$depense->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Show')
                ->has('depense')
            );
    }

    // ── Edit / Update ────────────────────────────────────────────────────────

    public function test_edit_renders_form_for_brouillon(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get("/depenses/{$depense->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Depenses/Edit'));
    }

    public function test_edit_forbidden_for_soumis(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get("/depenses/{$depense->id}/edit")->assertForbidden();
    }

    public function test_update_modifies_brouillon_depense(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 10000,
        ]);

        $this->put("/depenses/{$depense->id}", [
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 20000,
            'date_depense' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', ['id' => $depense->id, 'montant' => 20000]);
    }

    // ── Workflow ─────────────────────────────────────────────────────────────

    public function test_soumettre_transitions_brouillon_to_soumis(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/soumettre")->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }

    public function test_soumettre_fails_if_already_soumis(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/soumettre")
            ->assertSessionHasErrors(['statut']);
    }

    public function test_valider_transitions_soumis_to_valide(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/valider")->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::VALIDE->value,
            'validateur_id' => $this->user->id,
        ]);
    }

    public function test_rejeter_requires_motif(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/rejeter", [])
            ->assertSessionHasErrors(['motif_rejet']);
    }

    public function test_rejeter_transitions_soumis_to_rejete(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/rejeter", [
            'motif_rejet' => 'Justificatif insuffisant.',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::REJETE->value,
            'motif_rejet' => 'Justificatif insuffisant.',
        ]);
    }

    // ── Destroy ──────────────────────────────────────────────────────────────

    public function test_destroy_deletes_brouillon(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->delete("/depenses/{$depense->id}")->assertRedirect();

        $this->assertSoftDeleted('depenses', ['id' => $depense->id]);
    }

    public function test_destroy_forbidden_for_soumis(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->delete("/depenses/{$depense->id}")->assertForbidden();
    }

    public function test_destroy_forbidden_for_other_org(): void
    {
        $otherDepense = Depense::factory()->brouillon()->create();

        $this->delete("/depenses/{$otherDepense->id}")->assertForbidden();
    }
}
