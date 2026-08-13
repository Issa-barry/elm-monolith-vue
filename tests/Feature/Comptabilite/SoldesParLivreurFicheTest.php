<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CommissionPaymentService::soldesParLivreur() doit relire le solde côté fiche pour toute
 * part reprise par une PaiementFiche : un paiement de fiche (PaiementFichePaiementController)
 * ne met jamais à jour CommissionLogistiquePart.montant_verse/statut, cf. [[project_domain]]
 * DoublePaiementLockTest et le commentaire de PeriodePayabilityChecker::assertPartsNotClaimedByFiche.
 */
class SoldesParLivreurFicheTest extends TestCase
{
    use RefreshDatabase;

    private function makePart(Organization $org, Livreur $livreur, float $montant = 10000.0): CommissionLogistiquePart
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $site = Site::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Dépôt Test'],
            ['type' => 'depot', 'localisation' => 'Conakry']
        );
        $sysUser = User::factory()->create(['organization_id' => $org->id]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'reception',
            'date_arrivee_reelle' => '2026-04-12',
            'created_by' => $sysUser->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => $montant,
            'montant_total' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'taux_commission' => 100,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => '2026-04-12',
            'periode' => PeriodeComptableService::codeForLivreur(Carbon::parse('2026-04-12')),
        ]);
    }

    private function claimByFiche(CommissionLogistiquePart $part, float $ficheNet, float $fichePaye): PaiementFiche
    {
        $orgId = $part->commission->organization_id;
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $orgId,
            TypePeriodePaiement::LIVREUR,
            Carbon::parse($part->earned_at),
        );

        $fiche = PaiementFiche::create([
            'organization_id' => $orgId,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $part->livreur_id,
            'beneficiaire_nom' => $part->beneficiaire_nom,
            'montant_brut' => $ficheNet,
            'total_deductions' => 0,
            'montant_net' => $ficheNet,
            'montant_paye' => $fichePaye,
            'statut' => $fichePaye >= $ficheNet ? 'paye' : ($fichePaye > 0 ? 'partiellement_paye' : 'a_payer'),
        ]);

        PaiementFicheLigne::create([
            'fiche_id' => $fiche->id,
            'source_type' => CommissionLogistiquePart::class,
            'source_id' => $part->id,
            'type_ligne' => 'commission_logistique',
            'libelle' => 'Commission logistique test',
            'montant' => $part->montant_net,
            'ordre' => 1,
        ]);

        return $fiche;
    }

    /** @test */
    public function part_non_reclamee_par_une_fiche_lit_son_solde_directement(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $this->makePart($org, $livreur, 10000.0);

        $rows = CommissionPaymentService::soldesParLivreur($org->id);

        $row = $rows->firstWhere('livreur_id', $livreur->id);
        $this->assertNotNull($row);
        $this->assertSame(10000.0, $row->impaye);
        $this->assertSame(0.0, $row->paye);
        $this->assertNull($row->fiche_id);
    }

    /** @test */
    public function part_reclamee_par_une_fiche_non_payee_reste_impayee_et_expose_fiche_id(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $part = $this->makePart($org, $livreur, 10000.0);
        $fiche = $this->claimByFiche($part, 10000.0, 0.0);

        $rows = CommissionPaymentService::soldesParLivreur($org->id);
        $row = $rows->firstWhere('livreur_id', $livreur->id);

        $this->assertSame(10000.0, $row->impaye);
        $this->assertSame(0.0, $row->paye);
        $this->assertSame($fiche->id, $row->fiche_id);
    }

    /** @test */
    public function part_reclamee_par_une_fiche_partiellement_payee_repartit_la_fraction_payee(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $part = $this->makePart($org, $livreur, 10000.0);
        $this->claimByFiche($part, 10000.0, 2000.0);

        $rows = CommissionPaymentService::soldesParLivreur($org->id);
        $row = $rows->firstWhere('livreur_id', $livreur->id);

        $this->assertSame(2000.0, $row->paye);
        $this->assertSame(8000.0, $row->impaye);
    }

    /** @test */
    public function part_reclamee_par_une_fiche_integralement_payee_n_apparait_plus_comme_impayee(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $part = $this->makePart($org, $livreur, 24000.0);
        $this->claimByFiche($part, 24000.0, 24000.0);

        $rows = CommissionPaymentService::soldesParLivreur($org->id);
        $row = $rows->firstWhere('livreur_id', $livreur->id);

        $this->assertSame(0.0, $row->impaye);
        $this->assertSame(24000.0, $row->paye);
    }
}
