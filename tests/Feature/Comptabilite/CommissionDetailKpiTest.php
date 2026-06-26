<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutCommission;
use App\Features\ModuleFeature;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionVente;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionDetailKpiTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'categorie' => 'interne',
            'is_active' => true,
        ]);
    }

    private function makeLivreur(): Livreur
    {
        return Livreur::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeProprietaire(): Proprietaire
    {
        return Proprietaire::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeTransfert(Site $source, Site $dest, Vehicule $vehicule): TransfertLogistique
    {
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);
        $this->actingAs($this->user);

        return TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $source->id,
            'site_destination_id' => $dest->id,
            'vehicule_id' => $vehicule->id,
        ]);
    }

    private function makeCommissionLogistique(TransfertLogistique $transfert): CommissionLogistique
    {
        return CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $transfert->vehicule_id,
            'base_calcul' => BaseCalculLogistique::FORFAIT->value,
            'valeur_base' => 10000,
            'montant_total' => 10000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);
    }

    private function makePartLogistique(
        CommissionLogistique $commission,
        Livreur $livreur,
        float $brut = 10000,
        float $frais = 0,
        float $verse = 0,
        string $statut = 'impaye'
    ): CommissionLogistiquePart {
        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim($livreur->prenom.' '.$livreur->nom),
            'taux_commission' => 10,
            'montant_brut' => $brut,
            'frais_supplementaires' => $frais,
            'montant_net' => max(0.0, $brut - $frais),
            'montant_verse' => $verse,
            'statut' => $statut,
        ]);
    }

    // ── Logistique — structure des KPIs ──────────────────────────────────────

    public function test_logistique_kpis_exposent_brut_frais_net_verse(): void
    {
        $livreur = $this->makeLivreur();

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/logistique/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionLogistique/Livreur/Show')
                ->has('commission_summary.brut_cumule')
                ->has('commission_summary.frais')
                ->has('commission_summary.net_a_payer')
                ->has('commission_summary.deja_paye')
                ->has('commission_summary.reste_a_payer')
                ->where('commission_summary.brut_cumule', fn ($v) => (float) $v === 0.0)
                ->where('commission_summary.frais', fn ($v) => (float) $v === 0.0)
                ->where('commission_summary.net_a_payer', fn ($v) => (float) $v === 0.0)
                ->where('commission_summary.deja_paye', fn ($v) => (float) $v === 0.0)
                ->where('commission_summary.reste_a_payer', fn ($v) => (float) $v === 0.0)
            );
    }

    // ── Logistique — calcul net = brut - frais ────────────────────────────────

    public function test_logistique_net_a_payer_est_brut_moins_frais(): void
    {
        $siteA = $this->makeSite('Dabompa');
        $siteB = $this->makeSite('Kouria');
        $vehicule = $this->makeVehicule();
        $livreur = $this->makeLivreur();

        $transfert = $this->makeTransfert($siteA, $siteB, $vehicule);
        $commission = $this->makeCommissionLogistique($transfert);
        $this->makePartLogistique($commission, $livreur, brut: 10000, frais: 500, verse: 0, statut: 'impaye');

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/logistique/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commission_summary.brut_cumule', 10000)
                ->where('commission_summary.frais', 500)
                ->where('commission_summary.net_a_payer', 9500)
                ->where('commission_summary.deja_paye', fn ($v) => (float) $v === 0.0)
                ->where('commission_summary.reste_a_payer', 9500)
            );
    }

    // ── Logistique — bouton Payer conditionné par can_payer ──────────────────

    public function test_bouton_payer_expose_can_payer_vrai_avec_permission(): void
    {
        $livreur = $this->makeLivreur();

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/logistique/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_payer', true)
            );
    }

    // ── Logistique — paiement met à jour montant_verse et statut ─────────────

    public function test_paiement_logistique_met_a_jour_solde(): void
    {
        $siteA = $this->makeSite('Alpha');
        $siteB = $this->makeSite('Bêta');
        $vehicule = $this->makeVehicule();
        $livreur = $this->makeLivreur();

        $transfert = $this->makeTransfert($siteA, $siteB, $vehicule);
        $commission = $this->makeCommissionLogistique($transfert);
        $part = $this->makePartLogistique($commission, $livreur, brut: 10000, frais: 0, verse: 0, statut: 'impaye');

        $this->actingAs($this->user)
            ->post("/comptabilite/commissions/logistique/livreurs/{$livreur->id}/paiements", [
                'montant' => 4000,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $part->refresh();
        $this->assertEquals(4000.0, (float) $part->montant_verse);
        $this->assertEquals(StatutCommission::PARTIEL, $part->statut);
    }

    // ── Vente — resume_global contient total_frais ────────────────────────────

    public function test_vente_resume_global_inclut_frais(): void
    {
        $livreur = $this->makeLivreur();
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);
        $commission->parts()->create([
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim($livreur->prenom.' '.$livreur->nom),
            'role' => 'chauffeur',
            'taux_commission' => 10,
            'montant_brut' => 12000,
            'frais_supplementaires' => 1000,
            'montant_net' => 11000,
            'montant_verse' => 3000,
            'statut' => 'partiel',
        ]);

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/vente/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->where('commission_summary.brut_cumule', 12000)
                ->where('commission_summary.frais', 1000)
                ->where('commission_summary.net_a_payer', 11000)
                ->where('commission_summary.deja_paye', 3000)
                ->where('commission_summary.reste_a_payer', 8000)
            );
    }

    // ── Propriétaire — resume_global contient total_net_cumule ───────────────

    public function test_proprietaire_resume_global_inclut_net_a_payer(): void
    {
        $proprio = $this->makeProprietaire();
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);
        $commission->parts()->create([
            'type_beneficiaire' => 'proprietaire',
            'proprietaire_id' => $proprio->id,
            'beneficiaire_nom' => trim($proprio->prenom.' '.$proprio->nom),
            'role' => 'proprietaire',
            'taux_commission' => 60,
            'montant_brut' => 50000,
            'frais_supplementaires' => 0,
            'montant_net' => 50000,
            'montant_verse' => 10000,
            'statut' => 'partiel',
        ]);

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/proprietaires/{$proprio->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionProprietaire/Show')
                ->has('commission_summary.brut_cumule')
                ->has('commission_summary.frais')
                ->has('commission_summary.net_a_payer')
                ->has('commission_summary.deja_paye')
                ->has('commission_summary.reste_a_payer')
                ->where('commission_summary.brut_cumule', 50000)
                ->where('commission_summary.net_a_payer', 50000)
                ->where('commission_summary.deja_paye', 10000)
                ->where('commission_summary.reste_a_payer', 40000)
            );
    }

    // ── Structure commune : commission_details + expenses sur les 3 routes ───

    public function test_vente_commission_details_expose_montant_paye_reste_statut(): void
    {
        $livreur = $this->makeLivreur();
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);
        $commission->parts()->create([
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim($livreur->prenom.' '.$livreur->nom),
            'role' => 'chauffeur',
            'taux_commission' => 10,
            'montant_brut' => 12000,
            'frais_supplementaires' => 1000,
            'montant_net' => 11000,
            'montant_verse' => 3000,
            'statut' => 'partiel',
        ]);

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/vente/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commission_details', 1)
                ->where('commission_details.0.montant', 11000)
                ->where('commission_details.0.paye', 3000)
                ->where('commission_details.0.reste', 8000)
                ->has('commission_details.0.statut')
                ->has('expenses')
            );
    }

    public function test_logistique_commission_details_expose_montant_paye_reste_statut(): void
    {
        $siteA = $this->makeSite('Gamma');
        $siteB = $this->makeSite('Delta');
        $vehicule = $this->makeVehicule();
        $livreur = $this->makeLivreur();

        $transfert = $this->makeTransfert($siteA, $siteB, $vehicule);
        $commission = $this->makeCommissionLogistique($transfert);
        $this->makePartLogistique($commission, $livreur, brut: 10000, frais: 0, verse: 4000, statut: 'partiel');

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/logistique/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commission_details', 1)
                ->where('commission_details.0.montant', 10000)
                ->where('commission_details.0.paye', 4000)
                ->where('commission_details.0.reste', 6000)
                ->has('commission_details.0.statut')
                ->has('expenses')
            );
    }

    public function test_proprietaire_commission_details_expose_montant_paye_reste_statut(): void
    {
        $proprio = $this->makeProprietaire();
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);
        $commission->parts()->create([
            'type_beneficiaire' => 'proprietaire',
            'proprietaire_id' => $proprio->id,
            'beneficiaire_nom' => trim($proprio->prenom.' '.$proprio->nom),
            'role' => 'proprietaire',
            'taux_commission' => 60,
            'montant_brut' => 50000,
            'frais_supplementaires' => 0,
            'montant_net' => 50000,
            'montant_verse' => 10000,
            'statut' => 'partiel',
        ]);

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/proprietaires/{$proprio->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commission_details', 1)
                ->where('commission_details.0.montant', 50000)
                ->where('commission_details.0.paye', 10000)
                ->where('commission_details.0.reste', 40000)
                ->has('commission_details.0.statut')
                ->has('expenses')
            );
    }

    // ── Logistique — onglet Dépenses ajouté sans changer le KPI Frais ────────

    public function test_logistique_expenses_n_affecte_pas_le_kpi_frais(): void
    {
        $siteA = $this->makeSite('Epsilon');
        $siteB = $this->makeSite('Zeta');
        $vehicule = $this->makeVehicule();
        $livreur = $this->makeLivreur();

        $transfert = $this->makeTransfert($siteA, $siteB, $vehicule);
        $commission = $this->makeCommissionLogistique($transfert);
        $this->makePartLogistique($commission, $livreur, brut: 10000, frais: 500, verse: 0, statut: 'impaye');

        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => 2000,
        ]);

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/logistique/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('expenses', 1)
                ->where('expenses.0.montant', 2000)
                ->where('commission_summary.frais', 500)
            );
    }

    // ── Dépenses : seules les dépenses validées sont exposées ────────────────

    public function test_vente_expenses_n_inclut_pas_les_depenses_non_validees(): void
    {
        $livreur = $this->makeLivreur();
        Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => 1500,
        ]);

        $this->actingAs($this->user)
            ->get("/comptabilite/commissions/vente/livreurs/{$livreur->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('expenses', 0)
            );
    }
}
