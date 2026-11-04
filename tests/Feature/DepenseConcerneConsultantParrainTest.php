<?php

namespace Tests\Feature;

use App\Enums\CategorieDepense;
use App\Enums\EvenementComptable;
use App\Enums\PrestataireType;
use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\PieceComptable;
use App\Models\Prestataire;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Concernés « Consultant » et « Parrain » d'un type de dépense (03/11/2026) : Consultant est un
 * raccourci vers Prestataire limité aux consultants (dépense enregistrée comme prestataire, donc
 * déduite de la fiche consultant) ; Parrain est imputé à la quinzaine et déduit de la fiche Parrain
 * (PARRAIN-026), comptabilisé comme avance à récupérer (467220).
 */
class DepenseConcerneConsultantParrainTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['depenses.read', 'depenses.create', 'depenses.update', 'depenses.delete', 'parametres.read', 'parametres.update']);
        $this->actingAs($this->user);

        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 999_999_999,
        ]);
    }

    private function type(CategorieDepense $categorie, string $libelle): DepenseType
    {
        return DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'categorie' => $categorie->value,
            'libelle' => $libelle,
        ]);
    }

    private function prestataire(PrestataireType $type, string $nom, ?string $orgId = null): Prestataire
    {
        $orgId ??= $this->org->id;

        return Prestataire::create([
            'organization_id' => $orgId,
            'personne_id' => Personne::create(['organization_id' => $orgId, 'nom' => $nom, 'prenom' => 'Alpha'])->id,
            'type' => $type->value,
            'is_active' => true,
        ]);
    }

    private function parrain(string $nom = 'Sow', ?string $orgId = null): Parrain
    {
        return Parrain::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'nom' => $nom,
            'prenom' => 'Mariama',
            'telephone' => '+224 620 11 22 33',
        ]);
    }

    private function saisir(DepenseType $type, string $beneficiaireId)
    {
        return $this->post('/backoffice/depenses', [
            'depense_type_id' => $type->id,
            'beneficiaire_id' => $beneficiaireId,
            'montant' => 30000,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::BROUILLON->value,
        ]);
    }

    public function test_les_types_de_depense_acceptent_consultant_et_parrain(): void
    {
        foreach ([CategorieDepense::CONSULTANT, CategorieDepense::PARRAIN] as $categorie) {
            $this->post('/backoffice/depenses/types', [
                'libelle' => 'Avance '.$categorie->label(),
                'categorie' => $categorie->value,
                'commentaire_obligatoire' => false,
                'justificatif_obligatoire' => false,
                'is_active' => true,
            ])->assertSessionHasNoErrors();

            $this->assertDatabaseHas('depense_types', ['organization_id' => $this->org->id, 'categorie' => $categorie->value]);
        }

        $this->get('/backoffice/depenses/types')->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => collect($categories)->pluck('label')->intersect(['Consultant', 'Parrain'])->count() === 2));
    }

    public function test_le_formulaire_propose_les_parrains_et_le_type_des_prestataires(): void
    {
        $consultant = $this->prestataire(PrestataireType::CONSULTANT, 'Barry');
        $parrain = $this->parrain();
        $this->parrain('Autre', Organization::factory()->create()->id);

        $this->get('/backoffice/depenses/create')->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => collect($categories)->pluck('value')->intersect(['consultant', 'parrain'])->count() === 2)
            ->has('parrains', 1)
            ->where('parrains.0.id', $parrain->id)
            ->where('parrains.0.nom_complet', 'Mariama Sow')
            ->where('prestataires.0.id', $consultant->id)
            ->where('prestataires.0.type', 'consultant'));
    }

    public function test_une_depense_consultant_est_enregistree_comme_prestataire(): void
    {
        $type = $this->type(CategorieDepense::CONSULTANT, 'Frais de mission');
        $consultant = $this->prestataire(PrestataireType::CONSULTANT, 'Barry');

        $this->saisir($type, $consultant->id)->assertRedirect('/backoffice/depenses');

        $this->assertDatabaseHas('depenses', [
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'prestataire',
            'beneficiaire_id' => $consultant->id,
        ]);
    }

    public function test_le_concerne_consultant_refuse_un_prestataire_qui_n_est_pas_consultant(): void
    {
        $type = $this->type(CategorieDepense::CONSULTANT, 'Frais de mission');
        $mecanicien = $this->prestataire(PrestataireType::MECANICIEN, 'Camara');

        $this->saisir($type, $mecanicien->id)->assertSessionHasErrors('beneficiaire_id');
        $this->assertDatabaseCount('depenses', 0);

        // Le concerné Prestataire couvre toujours tous les prestataires, consultants compris.
        $this->saisir($this->type(CategorieDepense::PRESTATAIRE, 'Pièces'), $mecanicien->id)->assertSessionHasNoErrors();
    }

    public function test_une_depense_parrain_est_rattachee_au_parrain(): void
    {
        $type = $this->type(CategorieDepense::PARRAIN, 'Avance parrain');
        $parrain = $this->parrain();

        $this->saisir($type, $parrain->id)->assertRedirect('/backoffice/depenses');

        $this->assertDatabaseHas('depenses', [
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'parrain',
            'beneficiaire_id' => $parrain->id,
        ]);
    }

    public function test_un_parrain_d_une_autre_organisation_est_refuse(): void
    {
        $type = $this->type(CategorieDepense::PARRAIN, 'Avance parrain');
        $etranger = $this->parrain('Etranger', Organization::factory()->create()->id);

        $this->saisir($type, $etranger->id)->assertSessionHasErrors('beneficiaire_id');
        $this->assertDatabaseCount('depenses', 0);
    }

    public function test_valider_une_depense_parrain_l_impute_a_la_quinzaine_et_la_comptabilise_en_avance(): void
    {
        app(PlanComptableBootstrapService::class)->bootstrap($this->org->id);
        $parrain = $this->parrain();
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type(CategorieDepense::PARRAIN, 'Avance parrain')->id,
            'beneficiaire_type' => 'parrain',
            'beneficiaire_id' => $parrain->id,
            'montant' => 30000,
            'date_depense' => '2026-10-20',
        ]);

        $this->patch("/backoffice/depenses/{$depense->id}/valider")->assertRedirect();

        $this->assertDatabaseHas('depense_imputations', [
            'depense_id' => $depense->id,
            'imputation_type' => 'commission_parrain',
            'beneficiaire_type' => 'parrain',
            'beneficiaire_id' => $parrain->id,
            'periode_type' => 'quinzaine',
            'periode_debut' => '2026-10-16 00:00:00',
            'periode_fin' => '2026-10-31 00:00:00',
        ]);

        $piece = PieceComptable::where('source_type', $depense->getMorphClass())
            ->where('source_id', $depense->id)
            ->where('type_evenement', EvenementComptable::DEPENSE_AVANCE_TIERS_VALIDEE->value)
            ->firstOrFail();
        $lignes = $piece->lignes()->with('compte')->get()->keyBy(fn ($l) => $l->compte->numero);
        $this->assertEquals(30000.0, (float) $lignes['467220']->debit, 'Avance à récupérer sur le parrain.');
        $this->assertEquals(30000.0, (float) $lignes['571000']->credit);
    }

    public function test_la_liste_affiche_et_retrouve_le_parrain_concerne(): void
    {
        $type = $this->type(CategorieDepense::PARRAIN, 'Avance parrain');
        $parrain = $this->parrain();
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'parrain',
            'beneficiaire_id' => $parrain->id,
        ]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type(CategorieDepense::INTERNE, 'Électricité')->id,
        ]);

        $this->get('/backoffice/depenses?concerne=Sow')->assertInertia(fn (Assert $page) => $page
            ->has('depenses.data', 1)
            ->where('depenses.data.0.beneficiaire_label', 'Mariama Sow')
            ->where('depenses.data.0.beneficiaire_telephone', '+224 620 11 22 33')
            ->where('depenses.data.0.type.categorie_label', 'Parrain'));

        $this->get('/backoffice/depenses?telephone_concerne=620112233')
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
        $this->get('/backoffice/depenses?categorie[]=parrain')
            ->assertInertia(fn (Assert $page) => $page->has('depenses.data', 1));
    }

    public function test_la_fiche_de_la_depense_nomme_le_parrain(): void
    {
        $parrain = $this->parrain();
        $depense = Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type(CategorieDepense::PARRAIN, 'Avance parrain')->id,
            'beneficiaire_type' => 'parrain',
            'beneficiaire_id' => $parrain->id,
        ]);

        $this->get("/backoffice/depenses/{$depense->id}")->assertInertia(fn (Assert $page) => $page
            ->where('depense.beneficiaire_label', 'Mariama Sow')
            ->where('depense.categorie', 'parrain')
            ->where('depense.impact_message', CategorieDepense::PARRAIN->impactMessage()));

        $this->getJson("/backoffice/depenses/concerne-detail?type=parrain&id={$parrain->id}")
            ->assertOk()
            ->assertJsonPath('nom', 'Mariama Sow');
    }
}
