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
            'motif_rejet' => 'Non conforme',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::REJETE->value,
            'motif_rejet' => 'Non conforme',
            'commentaire_rejet' => null,
        ]);
    }

    public function test_rejeter_rejects_invalid_motif_value(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/rejeter", [
            'motif_rejet' => 'Justificatif insuffisant.',
        ])->assertSessionHasErrors(['motif_rejet']);
    }

    public function test_rejeter_autre_requires_commentaire(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/rejeter", [
            'motif_rejet' => 'Autre',
        ])->assertSessionHasErrors(['commentaire_rejet']);
    }

    public function test_rejeter_autre_commentaire_too_short_fails(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/rejeter", [
            'motif_rejet'       => 'Autre',
            'commentaire_rejet' => 'abc',
        ])->assertSessionHasErrors(['commentaire_rejet']);
    }

    public function test_rejeter_autre_saves_commentaire_rejet(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/rejeter", [
            'motif_rejet'       => 'Autre',
            'commentaire_rejet' => 'Montant incohérent avec le justificatif fourni.',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id'                => $depense->id,
            'statut'            => StatutDepense::REJETE->value,
            'motif_rejet'       => 'Autre',
            'commentaire_rejet' => 'Montant incohérent avec le justificatif fourni.',
        ]);
    }

    public function test_rejeter_non_conforme_sets_null_commentaire(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/rejeter", [
            'motif_rejet'       => 'Non conforme',
            'commentaire_rejet' => 'Ce commentaire doit être ignoré.',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id'                => $depense->id,
            'motif_rejet'       => 'Non conforme',
            'commentaire_rejet' => null,
        ]);
    }

    public function test_show_includes_commentaire_rejet(): void
    {
        $depense = Depense::factory()->create([
            'organization_id'   => $this->org->id,
            'user_id'           => $this->user->id,
            'depense_type_id'   => $this->typeInterne->id,
            'statut'            => StatutDepense::REJETE->value,
            'motif_rejet'       => 'Autre',
            'commentaire_rejet' => 'Montant incohérent.',
        ]);

        $this->get("/depenses/{$depense->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Show')
                ->where('depense.motif_rejet', 'Autre')
                ->where('depense.commentaire_rejet', 'Montant incohérent.')
            );
    }

    public function test_valider_auto_imputes_employe_depense(): void
    {
        $typeEmploye = DepenseType::factory()->employe()->create(['organization_id' => $this->org->id]);
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'statut' => 'actif']);

        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $employe->id,
            'montant' => 50000,
        ]);

        $this->patch("/depenses/{$depense->id}/valider")->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::VALIDE->value,
        ]);
        $this->assertDatabaseHas('depense_imputations', [
            'depense_id' => $depense->id,
            'beneficiaire_id' => $employe->id,
            'montant' => 50000,
            'statut' => 'impute',
        ]);
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function test_search_finds_by_commentaire(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'commentaire' => 'Achat gasoil pour groupe electrogene',
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'commentaire' => 'Frais de deplacement divers',
        ]);

        $this->get('/depenses?search=gasoil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_search_finds_by_type_libelle(): void
    {
        $typeSpecial = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant groupe',
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeSpecial->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get('/depenses?search=Carburant')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_search_does_not_leak_other_org(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'commentaire' => 'Achat materiel',
        ]);
        Depense::factory()->create(['commentaire' => 'Achat materiel']); // autre org

        $this->get('/depenses?search=materiel')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_index_includes_vehicule_nom_in_data(): void
    {
        $typeVehicule = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        Depense::factory()->create([
            'organization_id'  => $this->org->id,
            'user_id'          => $this->user->id,
            'depense_type_id'  => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id'  => $vehicule->id,
        ]);

        $this->get('/depenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Index')
                ->where('depenses.data.0.vehicule_nom', $vehicule->nom_vehicule)
            );
    }

    public function test_index_vehicule_nom_is_null_for_non_vehicule_depense(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get('/depenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.data.0.vehicule_nom', null)
            );
    }

    public function test_index_includes_validateur_after_validation(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}/valider");

        $this->get('/depenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.data.0.validateur.name', $this->user->name)
            );
    }

    public function test_index_validateur_is_null_for_non_validated_depense(): void
    {
        Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get('/depenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.data.0.validateur', null)
            );
    }

    // ── Exports ──────────────────────────────────────────────────────────────

    public function test_export_excel_returns_csv_download(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $response = $this->get('/depenses/export/excel');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_export_excel_respects_statut_filter(): void
    {
        Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);
        Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $response = $this->get('/depenses/export/excel?statut=brouillon');

        $response->assertOk();
        $content = $response->streamedContent();
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(2, $lines); // header + 1 data row
    }

    public function test_export_excel_has_reference_column(): void
    {
        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Référence', $content);
    }

    public function test_export_excel_has_valide_par_column(): void
    {
        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Validé par', $content);
    }

    public function test_export_excel_has_no_signature_column(): void
    {
        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringNotContainsString('Signature', $content);
    }

    public function test_export_excel_includes_validateur_name_when_validated(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);
        $this->patch("/depenses/{$depense->id}/valider");

        $response = $this->get('/depenses/export/excel?statut=valide');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString($this->user->name, $content);
    }

    public function test_export_excel_includes_depense_id_as_reference(): void
    {
        $depense = Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString($depense->id, $content);
    }

    public function test_export_pdf_with_site_filter_returns_single_pdf(): void
    {
        $site = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Matoto']);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id' => $site->id,
        ]);

        $response = $this->get("/depenses/export/pdf?site={$site->id}");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('matoto', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_export_pdf_without_site_filter_returns_single_pdf_when_one_site(): void
    {
        $site = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Dabompa']);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id' => $site->id,
        ]);

        $response = $this->get('/depenses/export/pdf');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_pdf_with_multiple_sites_returns_single_pdf(): void
    {
        $site1 = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Matoto']);
        $site2 = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Dabompa']);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id' => $site1->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id' => $site2->id,
        ]);

        $response = $this->get('/depenses/export/pdf');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Fichier unique — pas de ZIP dans le nom
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('depenses', $disposition);
        $this->assertStringNotContainsString('.zip', $disposition);
    }

    public function test_export_pdf_multi_site_template_has_page_break(): void
    {
        $html = view('pdf.depenses_multi', [
            'sites' => collect([
                ['site_nom' => 'Matoto',  'rows' => collect([]), 'total' => 0],
                ['site_nom' => 'Dabompa', 'rows' => collect([]), 'total' => 0],
            ]),
            'filters'      => [],
            'org'          => $this->org,
            'printed_by'   => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringContainsString('page-break-before', $html);
    }

    public function test_export_pdf_multi_site_template_has_signature_column(): void
    {
        $html = view('pdf.depenses_multi', [
            'sites'        => collect([['site_nom' => 'Matoto', 'rows' => collect([]), 'total' => 0]]),
            'filters'      => [],
            'org'          => $this->org,
            'printed_by'   => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringContainsString('Signature', $html);
    }

    public function test_export_pdf_multi_site_template_has_no_valide_par_column(): void
    {
        $html = view('pdf.depenses_multi', [
            'sites'        => collect([['site_nom' => 'Matoto', 'rows' => collect([]), 'total' => 0]]),
            'filters'      => [],
            'org'          => $this->org,
            'printed_by'   => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringNotContainsString('Validé par', $html);
    }

    public function test_pdf_template_excludes_valide_par_column(): void
    {
        $html = view('pdf.depenses', [
            'rows' => collect([]),
            'total' => 0,
            'filters' => [],
            'org' => $this->org,
            'site_nom' => 'Matoto',
            'printed_by' => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringNotContainsString('Validé par', $html);
    }

    public function test_pdf_template_has_signature_column(): void
    {
        $html = view('pdf.depenses', [
            'rows' => collect([]),
            'total' => 0,
            'filters' => [],
            'org' => $this->org,
            'site_nom' => 'Matoto',
            'printed_by' => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringContainsString('Signature', $html);
    }

    public function test_pdf_template_uses_org_name(): void
    {
        $html = view('pdf.depenses', [
            'rows' => collect([]),
            'total' => 0,
            'filters' => [],
            'org' => $this->org,
            'site_nom' => 'Matoto',
            'printed_by' => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringContainsString($this->org->name, $html);
        $this->assertStringNotContainsString($this->org->slug, $html);
    }

    public function test_pdf_template_has_printed_by(): void
    {
        $html = view('pdf.depenses', [
            'rows' => collect([]),
            'total' => 0,
            'filters' => [],
            'org' => $this->org,
            'site_nom' => 'Matoto',
            'printed_by' => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringContainsString('Imprimé le', $html);
        $this->assertStringContainsString($this->user->name, $html);
    }

    public function test_pdf_template_has_site_in_title(): void
    {
        $html = view('pdf.depenses', [
            'rows' => collect([]),
            'total' => 0,
            'filters' => [],
            'org' => $this->org,
            'site_nom' => 'Matoto',
            'printed_by' => $this->user->name,
            'generated_at' => now(),
        ])->render();

        $this->assertStringContainsString('Matoto', $html);
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

    // ── Filtre REJETE ─────────────────────────────────────────────────────────

    public function test_filtre_rejete_retourne_depenses_rejetees(): void
    {
        Depense::factory()->rejete()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);
        Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get('/depenses?statut=rejete')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('depenses.data', 1)
                ->where('depenses.data.0.statut', 'rejete')
            );
    }

    public function test_update_allowed_from_rejete_status(): void
    {
        $depense = Depense::factory()->rejete()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}", [
            'depense_type_id' => $this->typeInterne->id,
            'montant'         => 75000,
            'date_depense'    => '2026-06-14',
            'commentaire'     => 'Corrigé après rejet',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id'      => $depense->id,
            'montant' => 75000,
        ]);
    }

    // ── Imprimer ──────────────────────────────────────────────────────────────

    public function test_imprimer_returns_ok_html(): void
    {
        $this->get('/depenses/imprimer')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_imprimer_has_signature_column(): void
    {
        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringContainsString('Signature', $content);
    }

    public function test_imprimer_has_no_valide_par_column(): void
    {
        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringNotContainsString('Validé par', $content);
    }

    public function test_imprimer_has_window_print_script(): void
    {
        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringContainsString('window.print()', $content);
    }

    public function test_imprimer_has_a4_print_rule(): void
    {
        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringContainsString('size: A4', $content);
    }

    public function test_imprimer_respects_statut_filter(): void
    {
        // Dates distinctes visibles dans le rendu (d/m/Y)
        Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'date_depense'    => '2020-01-15',
        ]);
        Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'date_depense'    => '2020-02-20',
        ]);

        $content = $this->get('/depenses/imprimer?statut=brouillon')->content();
        $this->assertStringContainsString('15/01/2020', $content);
        $this->assertStringNotContainsString('20/02/2020', $content);
    }

    public function test_imprimer_groups_by_site_with_page_break(): void
    {
        $site1 = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Matoto']);
        $site2 = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Dabompa']);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id'         => $site1->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id'         => $site2->id,
        ]);

        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringContainsString('page-break-after', $content);
        $this->assertStringContainsString('Matoto', $content);
        $this->assertStringContainsString('Dabompa', $content);
    }

    public function test_imprimer_single_site_filter_shows_only_that_site(): void
    {
        $site1 = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Conakry']);
        $site2 = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Kindia']);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id'         => $site1->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id'         => $site2->id,
        ]);

        $content = $this->get("/depenses/imprimer?site={$site1->id}")->content();
        $this->assertStringContainsString('Conakry', $content);
        $this->assertStringNotContainsString('Kindia', $content);
    }

    public function test_imprimer_shows_org_name(): void
    {
        // Le nom org n'est rendu que si au moins une dépense existe (dans le @foreach)
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringContainsString($this->org->name, $content);
    }

    public function test_imprimer_shows_printed_by(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringContainsString($this->user->name, $content);
    }

    public function test_imprimer_forbidden_for_unauthenticated(): void
    {
        $this->app['auth']->logout();
        $this->get('/depenses/imprimer')->assertRedirect('/login');
    }
}
