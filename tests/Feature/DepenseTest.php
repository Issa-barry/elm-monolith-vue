<?php

namespace Tests\Feature;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Employe;
use App\Models\Livreur;
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
            'motif_rejet' => 'Autre',
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
            'motif_rejet' => 'Autre',
            'commentaire_rejet' => 'Montant incohérent avec le justificatif fourni.',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::REJETE->value,
            'motif_rejet' => 'Autre',
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
            'motif_rejet' => 'Non conforme',
            'commentaire_rejet' => 'Ce commentaire doit être ignoré.',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'motif_rejet' => 'Non conforme',
            'commentaire_rejet' => null,
        ]);
    }

    public function test_show_includes_commentaire_rejet(): void
    {
        $depense = Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'statut' => StatutDepense::REJETE->value,
            'motif_rejet' => 'Autre',
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

    public function test_search_finds_by_vehicule_nom(): void
    {
        $typeVehicule = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Camion ELM 12']);
        $autreVehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Moto 7']);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $autreVehicule->id,
        ]);

        $this->get('/depenses?search=Camion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_search_finds_by_vehicule_immatriculation(): void
    {
        $typeVehicule = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'immatriculation' => 'ELM-001-GN']);
        $autreVehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'immatriculation' => 'ELM-999-GN']);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $autreVehicule->id,
        ]);

        $this->get('/depenses?search=ELM-001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_search_finds_by_concerne_nom(): void
    {
        $typeEmploye = DepenseType::factory()->employe()->create(['organization_id' => $this->org->id]);
        $employe = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'nom' => 'Diallo',
            'prenom' => 'Amadou',
        ]);
        $autreEmploye = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'nom' => 'Camara',
            'prenom' => 'Fatou',
        ]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $employe->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $autreEmploye->id,
        ]);

        $this->get('/depenses?search=Diallo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_search_finds_by_concerne_telephone(): void
    {
        $typeLivreur = DepenseType::factory()->livreur()->create([
            'organization_id' => $this->org->id,
        ]);
        $livreur = Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224611223344',
        ]);
        $autreLivreur = Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224699887766',
        ]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeLivreur->id,
            'beneficiaire_type' => CategorieDepense::LIVREUR->value,
            'beneficiaire_id' => $livreur->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeLivreur->id,
            'beneficiaire_type' => CategorieDepense::LIVREUR->value,
            'beneficiaire_id' => $autreLivreur->id,
        ]);

        $this->get('/depenses?search=611223344')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_search_with_nonexistent_value_returns_empty_without_error(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'commentaire' => 'Achat materiel',
        ]);

        $this->get('/depenses?search=zzzzznotfound')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 0));
    }

    public function test_search_empty_does_not_crash_and_returns_all(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->get('/depenses?search=')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 2));
    }

    public function test_search_does_not_crash_when_no_depenses_have_vehicule_beneficiaire(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'beneficiaire_type' => null,
            'beneficiaire_id' => null,
        ]);

        $this->get('/depenses?search=ELM-001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 0));
    }

    public function test_index_includes_vehicule_nom_in_data(): void
    {
        $typeVehicule = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
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
            'user_id' => $this->user->id,
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
            'user_id' => $this->user->id,
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
            'user_id' => $this->user->id,
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
            'user_id' => $this->user->id,
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
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString($depense->id, $content);
    }

    public function test_export_excel_has_telephone_concerne_column(): void
    {
        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Téléphone concerné', $content);
    }

    public function test_export_excel_has_frais_column(): void
    {
        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Frais', $content);
    }

    public function test_export_excel_includes_telephone_for_employe_beneficiaire(): void
    {
        $typeEmploye = DepenseType::factory()->employe()->create(['organization_id' => $this->org->id]);
        $employe = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'telephone' => '+224600000000',
        ]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $employe->id,
        ]);

        $response = $this->get('/depenses/export/excel');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('+224600000000', $content);
    }

    public function test_pdf_route_no_longer_exists(): void
    {
        $this->get('/depenses/export/pdf')->assertNotFound();
    }

    // ── Filtre véhicule ──────────────────────────────────────────────────────

    public function test_filtre_vehicule_par_nom(): void
    {
        $typeVehicule = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Camion ELM 12']);
        $autreVehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Moto 7']);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $autreVehicule->id,
        ]);

        $this->get('/depenses?vehicule=Camion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_filtre_vehicule_par_immatriculation(): void
    {
        $typeVehicule = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'immatriculation' => 'ELM-001-GN']);
        $autreVehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'immatriculation' => 'ELM-999-GN']);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeVehicule->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $autreVehicule->id,
        ]);

        $this->get('/depenses?vehicule=ELM-001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    // ── Filtre concerné ──────────────────────────────────────────────────────

    public function test_filtre_concerne_par_nom(): void
    {
        $typeEmploye = DepenseType::factory()->employe()->create(['organization_id' => $this->org->id]);
        $employe = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'nom' => 'Diallo',
            'prenom' => 'Amadou',
        ]);
        $autreEmploye = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'nom' => 'Camara',
            'prenom' => 'Fatou',
        ]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $employe->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $autreEmploye->id,
        ]);

        $this->get('/depenses?concerne=Diallo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_filtre_concerne_par_telephone(): void
    {
        $typeEmploye = DepenseType::factory()->employe()->create(['organization_id' => $this->org->id]);
        $employe = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'telephone' => '+224611223344',
        ]);
        $autreEmploye = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'telephone' => '+224699887766',
        ]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $employe->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $typeEmploye->id,
            'beneficiaire_type' => CategorieDepense::EMPLOYE->value,
            'beneficiaire_id' => $autreEmploye->id,
        ]);

        $this->get('/depenses?concerne=611223344')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    // ── Filtre montant ───────────────────────────────────────────────────────

    public function test_filtre_montant_exact(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 75000,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 30000,
        ]);

        $this->get('/depenses?montant=75000')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('depenses.data', 1)
                ->where('depenses.data.0.montant', 75000)
            );
    }

    // ── Filtre agence (site_ids) ────────────────────────────────────────────

    public function test_filtre_site_ids_retourne_uniquement_depenses_des_sites_selectionnes(): void
    {
        $site1 = $this->user->sites()->first();
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $site3 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 3',
            'type' => 'depot',
            'localisation' => 'Mamou',
        ]);

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
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id' => $site3->id,
        ]);

        $this->get('/depenses?'.http_build_query(['site_ids' => [$site1->id, $site2->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 2));
    }

    public function test_filtre_site_ids_est_renvoye_dans_les_filters_pour_persister_apres_apply(): void
    {
        $site = $this->user->sites()->first();

        $this->get('/depenses?'.http_build_query(['site_ids' => [$site->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.site_ids', [$site->id])
            );
    }

    public function test_filtre_site_ids_vide_retourne_toutes_les_depenses(): void
    {
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id' => $this->user->sites()->first()->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'site_id' => $site2->id,
        ]);

        $this->get('/depenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('depenses.data', 2)
                ->where('filters.site_ids', [])
            );
    }

    // ── Popups détail ────────────────────────────────────────────────────────

    public function test_concerne_detail_endpoint_returns_employe_info(): void
    {
        $employe = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => 'actif',
            'nom' => 'Diallo',
            'prenom' => 'Amadou',
            'telephone' => '+224600000000',
        ]);

        $response = $this->getJson("/depenses/concerne-detail?type=employe&id={$employe->id}");

        $response->assertOk()
            ->assertJson([
                'type' => 'employe',
                'nom' => 'Amadou Diallo',
                'telephone' => '+224600000000',
            ]);
    }

    public function test_concerne_detail_endpoint_returns_404_for_unknown_id(): void
    {
        $this->getJson('/depenses/concerne-detail?type=employe&id=unknown')
            ->assertNotFound();
    }

    public function test_vehicule_detail_endpoint_returns_vehicule_info(): void
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'nom_vehicule' => 'Camion ELM 12',
            'immatriculation' => 'ELM-001-GN',
        ]);

        $response = $this->getJson("/depenses/vehicule-detail?id={$vehicule->id}");

        $response->assertOk()
            ->assertJson([
                'nom' => 'Camion ELM 12',
                'immatriculation' => 'ELM-001-GN',
            ]);
    }

    public function test_vehicule_detail_endpoint_returns_404_for_unknown_id(): void
    {
        $this->getJson('/depenses/vehicule-detail?id=unknown')
            ->assertNotFound();
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
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);
        Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
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
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $this->patch("/depenses/{$depense->id}", [
            'depense_type_id' => $this->typeInterne->id,
            'montant' => 75000,
            'date_depense' => '2026-06-14',
            'commentaire' => 'Corrigé après rejet',
        ])->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
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
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'date_depense' => '2020-01-15',
        ]);
        Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
            'date_depense' => '2020-02-20',
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

        $content = $this->get('/depenses/imprimer?'.http_build_query(['site_ids' => [$site1->id]]))->content();
        $this->assertStringContainsString('Conakry', $content);
        $this->assertStringNotContainsString('Kindia', $content);
    }

    public function test_imprimer_shows_org_name(): void
    {
        // Le nom org n'est rendu que si au moins une dépense existe (dans le @foreach)
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->typeInterne->id,
        ]);

        $content = $this->get('/depenses/imprimer')->content();
        $this->assertStringContainsString($this->org->name, $content);
    }

    public function test_imprimer_shows_printed_by(): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
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
