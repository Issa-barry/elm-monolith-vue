<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutDepense;
use App\Enums\StatutLignePaie;
use App\Enums\StatutPeriodePaie;
use App\Enums\TypeVariablePaie;
use App\Features\ModuleFeature;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommandeVente;
use App\Models\Contrat;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\JournalTresorerie;
use App\Models\PaieVariable;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use App\Services\PaieCalculService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class DepenseComptabiliteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private PaieCalculService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
        $this->service = app(PaieCalculService::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeEmployeAvecContrat(float $salaire = 1_000_000): array
    {
        static $seq = 0;
        $seq++;

        $employe = Employe::create([
            'organization_id' => $this->org->id,
            'matricule' => 'EMP-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'nom' => 'TEST',
            'prenom' => 'Salarié',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);

        $contrat = Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_contrat' => 'cdi',
            'date_debut' => '2024-01-01',
            'date_fin' => null,
            'salaire_base' => $salaire,
            'statut_contrat' => 'actif',
        ]);

        return [$employe, $contrat];
    }

    private function makePeriode(int $mois = 6, int $annee = 2026): PaiePeriode
    {
        return PaiePeriode::create([
            'organization_id' => $this->org->id,
            'mois' => $mois,
            'annee' => $annee,
            'statut' => StatutPeriodePaie::BROUILLON,
        ]);
    }

    private function makeLigne(Employe $employe, Contrat $contrat, PaiePeriode $periode, float $salaire = 1_000_000): PaieLigne
    {
        return PaieLigne::create([
            'paie_periode_id' => $periode->id,
            'employe_id' => $employe->id,
            'contrat_id' => $contrat->id,
            'salaire_base' => $salaire,
            'jours_travailles' => 30,
            'jours_periode' => 30,
            'total_primes' => 0,
            'total_autres_gains' => 0,
            'total_avances' => 0,
            'total_retenues' => 0,
            'total_absences' => 0,
            'total_autres_deductions' => 0,
            'brut' => $salaire,
            'deductions' => 0,
            'net' => $salaire,
            'deja_paye' => 0,
            'reste_a_payer' => $salaire,
            'statut' => StatutLignePaie::EN_ATTENTE,
        ]);
    }

    private function makeDepenseType(): DepenseType
    {
        return DepenseType::factory()->employe()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Avance salaire',
            'type_paie' => null,
        ]);
    }

    // ── PaieCalculService::importerDepenses ───────────────────────────────────

    public function test_importer_depenses_importe_depense_validee_employe(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat(2_000_000);
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode, 2_000_000);
        $type = $this->makeDepenseType();

        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 80_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->service->importerDepenses($ligne, $periode);

        $this->assertDatabaseHas('paie_variables', [
            'paie_ligne_id' => $ligne->id,
            'type' => TypeVariablePaie::AUTRE_DEDUCTION->value,
            'montant' => '80000.00',
        ]);
    }

    public function test_importer_depenses_ignore_depense_non_validee(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat();
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode);
        $type = $this->makeDepenseType();

        Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 50_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->service->importerDepenses($ligne, $periode);

        $this->assertDatabaseMissing('paie_variables', ['paie_ligne_id' => $ligne->id]);
    }

    public function test_importer_depenses_ignore_depense_hors_periode(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat();
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode);
        $type = $this->makeDepenseType();

        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 50_000,
            'date_depense' => '2026-05-15',
        ]);

        $this->service->importerDepenses($ligne, $periode);

        $this->assertDatabaseMissing('paie_variables', ['paie_ligne_id' => $ligne->id]);
    }

    public function test_importer_depenses_ignore_depense_autre_employe(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat();
        [$autreEmploye] = $this->makeEmployeAvecContrat();
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode);
        $type = $this->makeDepenseType();

        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $autreEmploye->id,
            'montant' => 50_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->service->importerDepenses($ligne, $periode);

        $this->assertDatabaseMissing('paie_variables', ['paie_ligne_id' => $ligne->id]);
    }

    public function test_calculer_ligne_deduit_depenses_du_net(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat(2_000_000);
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode, 2_000_000);
        $type = $this->makeDepenseType();

        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 80_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->service->importerDepenses($ligne, $periode);
        $ligne->load('variables');
        $this->service->calculerLigne($ligne);

        $ligne->refresh();
        $this->assertEquals(2_000_000, (float) $ligne->brut);
        $this->assertEquals(80_000, (float) $ligne->deductions);
        $this->assertEquals(1_920_000, (float) $ligne->net);
        $this->assertEquals(1_920_000, (float) $ligne->reste_a_payer);
    }

    public function test_importer_depenses_supprime_variables_precedentes_et_reimporte(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat(1_000_000);
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode, 1_000_000);
        $type = $this->makeDepenseType();

        $depense = Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 50_000,
            'date_depense' => '2026-06-05',
        ]);

        $this->service->importerDepenses($ligne, $periode);
        $this->assertCount(1, $ligne->fresh()->variables);

        $this->service->importerDepenses($ligne, $periode);
        $this->assertCount(1, $ligne->fresh()->variables);
    }

    // ── DepenseObserver ───────────────────────────────────────────────────────

    public function test_observer_recalcule_ligne_quand_depense_validee(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat(1_500_000);
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode, 1_500_000);
        $type = $this->makeDepenseType();

        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 100_000,
            'date_depense' => '2026-06-15',
        ]);

        $this->assertDatabaseMissing('paie_variables', ['paie_ligne_id' => $ligne->id]);

        $depense->update(['statut' => StatutDepense::VALIDE->value]);

        $this->assertDatabaseHas('paie_variables', [
            'paie_ligne_id' => $ligne->id,
            'montant' => '100000.00',
        ]);

        $ligne->refresh();
        $this->assertEquals(1_400_000, (float) $ligne->net);
    }

    public function test_observer_ne_fait_rien_si_statut_inchange(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat();
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode);
        $type = $this->makeDepenseType();

        $depense = Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 50_000,
            'date_depense' => '2026-06-10',
        ]);

        PaieVariable::query()->delete();

        $depense->update(['commentaire' => 'modification commentaire']);

        $this->assertDatabaseMissing('paie_variables', ['paie_ligne_id' => $ligne->id]);
    }

    public function test_observer_ignore_beneficiaire_non_employe(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $type = $this->makeDepenseType();

        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => 50_000,
            'date_depense' => '2026-06-10',
        ]);

        $depense->update(['statut' => StatutDepense::VALIDE->value]);

        $this->assertDatabaseMissing('paie_variables', []);
    }

    public function test_observer_retire_variable_quand_depense_rejetee(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat(2_000_000);
        $periode = $this->makePeriode(6, 2026);
        $ligne = $this->makeLigne($employe, $contrat, $periode, 2_000_000);
        $type = $this->makeDepenseType();

        $depense = Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 200_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->service->importerDepenses($ligne, $periode);
        $ligne->load('variables');
        $this->service->calculerLigne($ligne);

        $this->assertDatabaseHas('paie_variables', ['paie_ligne_id' => $ligne->id]);
        $this->assertEquals(1_800_000, (float) $ligne->fresh()->net);

        $depense->update(['statut' => StatutDepense::REJETE->value]);

        $this->assertDatabaseMissing('paie_variables', ['paie_ligne_id' => $ligne->id]);
        $this->assertEquals(2_000_000, (float) $ligne->fresh()->net);
    }

    public function test_observer_cree_entree_journal_quand_depense_interne_validee(): void
    {
        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Fournitures bureau',
        ]);

        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => null,
            'beneficiaire_id' => null,
            'montant' => 75_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->assertDatabaseMissing('journal_tresorerie', ['source_id' => $depense->id]);

        $depense->update(['statut' => StatutDepense::VALIDE->value]);

        $this->assertDatabaseHas('journal_tresorerie', [
            'organization_id' => $this->org->id,
            'source_type' => \App\Models\Depense::class,
            'source_id' => $depense->id,
            'montant' => '75000.00',
            'sens' => 'sortie',
            'categorie' => 'depense_interne',
        ]);
    }

    public function test_observer_supprime_entree_journal_quand_depense_interne_rejetee(): void
    {
        $type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant',
        ]);

        $depense = Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => null,
            'beneficiaire_id' => null,
            'montant' => 40_000,
            'date_depense' => '2026-06-12',
        ]);

        JournalTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => null,
            'date_operation' => '2026-06-12',
            'sens' => 'sortie',
            'categorie' => 'depense_interne',
            'libelle' => 'Carburant',
            'montant' => 40_000,
            'source_type' => \App\Models\Depense::class,
            'source_id' => $depense->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertDatabaseHas('journal_tresorerie', ['source_id' => $depense->id]);

        $depense->update(['statut' => StatutDepense::REJETE->value]);

        $this->assertDatabaseMissing('journal_tresorerie', ['source_id' => $depense->id]);
    }

    // ── SalaireController — auto-génération ──────────────────────────────────

    public function test_index_auto_genere_periode_et_lignes_a_premiere_visite(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat(1_200_000);

        $this->actingAs($this->user)
            ->get('/comptabilite/salaires?mois=6&annee=2026')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Salaire/Index')
                ->has('lignes', 1)
                ->where('lignes.0.salaire_base', 1_200_000)
            );

        $this->assertDatabaseHas('paie_periodes', [
            'organization_id' => $this->org->id,
            'mois' => 6,
            'annee' => 2026,
        ]);

        $this->assertDatabaseHas('paie_lignes', [
            'employe_id' => $employe->id,
        ]);
    }

    public function test_index_ne_duplique_pas_les_lignes_a_visite_suivante(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat();

        $this->actingAs($this->user)->get('/comptabilite/salaires?mois=6&annee=2026');
        $this->actingAs($this->user)->get('/comptabilite/salaires?mois=6&annee=2026');

        $this->assertDatabaseCount('paie_lignes', 1);
    }

    public function test_index_affiche_depenses_validees_comme_deductions(): void
    {
        [$employe, $contrat] = $this->makeEmployeAvecContrat(1_800_000);
        $type = $this->makeDepenseType();

        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $employe->id,
            'montant' => 80_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->actingAs($this->user)
            ->get('/comptabilite/salaires?mois=6&annee=2026')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lignes.0.deductions', 80_000)
                ->where('lignes.0.net', 1_720_000)
            );
    }

    // ── Commission logistique — frais_depenses déduits ────────────────────────

    public function test_commission_logistique_deduit_depenses_validees_livreur(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $site = Site::firstOrCreate(
            ['organization_id' => $this->org->id, 'nom' => 'Dépôt Test'],
            ['type' => 'depot']
        );
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'reception',
            'created_by' => $this->user->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 200_000,
            'montant_total' => 200_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet ?? 'Livreur Test',
            'taux_commission' => 100,
            'montant_brut' => 200_000,
            'frais_supplementaires' => 0,
            'montant_net' => 200_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
            'earned_at' => now(),
            'periode' => '2026-06-L',
        ]);

        $type = $this->makeDepenseType();
        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => 30_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->actingAs($this->user)
            ->get('/comptabilite/commissions/logistique')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionLogistique/Index')
                ->where('livreurs.0.frais_depenses', 30_000)
                ->where('livreurs.0.impaye', 170_000)
            );
    }

    // ── Commission vente — frais_depenses déduits du net ─────────────────────

    public function test_commission_vente_deduit_depenses_validees_livreur(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $commandeVente = CommandeVente::factory()->create(['organization_id' => $this->org->id]);
        $commissionVente = \App\Models\CommissionVente::factory()->create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'commande_vente_id' => $commandeVente->id,
        ]);

        $commissionVente->parts()->create([
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => 'Livreur Test',
            'role' => 'chauffeur',
            'taux_commission' => 20,
            'montant_brut' => 150_000,
            'frais_supplementaires' => 0,
            'montant_net' => 150_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $type = $this->makeDepenseType();
        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => 20_000,
            'date_depense' => '2026-06-10',
        ]);

        $this->actingAs($this->user)
            ->get('/comptabilite/commissions/vente')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Index')
                ->where('beneficiaires.0.total_net_cumule', 130_000)
                ->where('beneficiaires.0.solde_restant', 130_000)
            );
    }

    // ── Commission propriétaire — frais véhicule déjà déduits ────────────────

    public function test_commission_proprietaire_deduit_depenses_vehicule(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $commandeVenteP = CommandeVente::factory()->create(['organization_id' => $this->org->id]);
        $commissionVente = \App\Models\CommissionVente::factory()->create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'commande_vente_id' => $commandeVenteP->id,
        ]);

        $commissionVente->parts()->create([
            'type_beneficiaire' => 'proprietaire',
            'proprietaire_id' => $proprietaire->id,
            'beneficiaire_nom' => 'Propriétaire Test',
            'role' => 'proprietaire',
            'taux_commission' => 30,
            'montant_brut' => 300_000,
            'frais_supplementaires' => 0,
            'montant_net' => 300_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $type = DepenseType::factory()->vehicule()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Réparation',
        ]);

        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'montant' => 50_000,
            'date_depense' => '2026-06-12',
        ]);

        $this->actingAs($this->user)
            ->get('/comptabilite/commissions/proprietaires')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionProprietaire/Index')
                ->where('beneficiaires.0.total_frais', 50_000)
                ->where('beneficiaires.0.total_net_cumule', 250_000)
                ->where('beneficiaires.0.solde_restant', 250_000)
            );
    }
}
