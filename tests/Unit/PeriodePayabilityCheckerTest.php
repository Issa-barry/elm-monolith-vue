<?php

namespace Tests\Unit;

use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaie;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiePeriode;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\PeriodePaiementService;
use App\Services\PeriodePayabilityChecker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PeriodePayabilityCheckerTest extends TestCase
{
    use RefreshDatabase;

    // ── assertPeriodePayable() : PaiementPeriode ──────────────────────────────

    public function test_assert_periode_payable_refuse_en_brouillon(): void
    {
        $periode = $this->makePaiementPeriode(StatutPeriodePaiement::BROUILLON);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/n'est pas validée/i");

        PeriodePayabilityChecker::assertPeriodePayable($periode);
    }

    public function test_assert_periode_payable_refuse_en_calculee(): void
    {
        $periode = $this->makePaiementPeriode(StatutPeriodePaiement::CALCULEE);

        $this->expectException(InvalidArgumentException::class);
        PeriodePayabilityChecker::assertPeriodePayable($periode);
    }

    public function test_assert_periode_payable_refuse_en_cloturee(): void
    {
        $periode = $this->makePaiementPeriode(StatutPeriodePaiement::CLOTUREE);

        $this->expectException(InvalidArgumentException::class);
        PeriodePayabilityChecker::assertPeriodePayable($periode);
    }

    public function test_assert_periode_payable_autorise_en_validee(): void
    {
        $periode = $this->makePaiementPeriode(StatutPeriodePaiement::VALIDEE);

        PeriodePayabilityChecker::assertPeriodePayable($periode);
        $this->addToAssertionCount(1); // aucune exception levée
    }

    // ── assertPeriodePayable() : PaiePeriode ──────────────────────────────────

    public function test_assert_periode_payable_paie_refuse_en_brouillon_et_calcule(): void
    {
        $org = Organization::factory()->create();

        foreach ([StatutPeriodePaie::BROUILLON, StatutPeriodePaie::CALCULE, StatutPeriodePaie::CLOTURE] as $i => $statut) {
            $periode = PaiePeriode::create([
                'organization_id' => $org->id,
                'mois' => $i + 1,
                'annee' => 2026,
                'statut' => $statut->value,
            ]);

            try {
                PeriodePayabilityChecker::assertPeriodePayable($periode);
                $this->fail("Le statut {$statut->value} aurait dû être refusé.");
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression("/n'est pas validée/i", $e->getMessage());
            }
        }
    }

    public function test_assert_periode_payable_paie_autorise_valide_rh_et_paye(): void
    {
        $org = Organization::factory()->create();

        foreach ([StatutPeriodePaie::VALIDE_RH, StatutPeriodePaie::PAYE] as $i => $statut) {
            $periode = PaiePeriode::create([
                'organization_id' => $org->id,
                'mois' => $i + 1,
                'annee' => 2026,
                'statut' => $statut->value,
            ]);

            PeriodePayabilityChecker::assertPeriodePayable($periode);
        }

        $this->addToAssertionCount(1);
    }

    // ── periodeForCommissionPart() / reasonPartNotPayable() : CommissionLogistiquePart ──

    public function test_reason_part_not_payable_signale_periode_non_calculee(): void
    {
        ['part' => $part] = $this->makeCommissionLogistiqueScenario();
        // Aucune PaiementPeriode n'est créée pour la date de la part.

        $this->assertNull(PeriodePayabilityChecker::periodeForCommissionPart($part));

        $reason = PeriodePayabilityChecker::reasonPartNotPayable($part);
        $this->assertNotNull($reason);
        $this->assertMatchesRegularExpression("/n'a pas encore été calculée/i", $reason);
    }

    public function test_reason_part_not_payable_signale_periode_non_validee(): void
    {
        ['org' => $org, 'part' => $part] = $this->makeCommissionLogistiqueScenario();
        $this->validerPeriode($org, TypePeriodePaiement::LIVREUR, $part->earned_at, StatutPeriodePaiement::CALCULEE);

        $reason = PeriodePayabilityChecker::reasonPartNotPayable($part);
        $this->assertNotNull($reason);
        $this->assertMatchesRegularExpression("/n'est pas validée/i", $reason);
    }

    public function test_reason_part_not_payable_null_si_periode_validee(): void
    {
        ['org' => $org, 'part' => $part] = $this->makeCommissionLogistiqueScenario();
        $this->validerPeriode($org, TypePeriodePaiement::LIVREUR, $part->earned_at, StatutPeriodePaiement::VALIDEE);

        $this->assertNull(PeriodePayabilityChecker::reasonPartNotPayable($part));
    }

    // ── periodeForCommissionPart() : CommissionPart (vente) ────────────────────

    public function test_reason_part_not_payable_fonctionne_pour_commission_part_vente(): void
    {
        $org = Organization::factory()->create();
        $commission = CommissionVente::factory()->create([
            'organization_id' => $org->id,
            'montant_commission_totale' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);
        $part = $commission->parts()->create([
            'type_beneficiaire' => 'proprietaire',
            'beneficiaire_nom' => 'Camara Ibrahim',
            'taux_commission' => 40,
            'montant_brut' => 3000,
            'frais_supplementaires' => 0,
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);

        // Pas de période créée : refusé faute de calcul.
        $this->assertNotNull(PeriodePayabilityChecker::reasonPartNotPayable($part));

        $this->validerPeriode($org, TypePeriodePaiement::PROPRIETAIRE, $commission->created_at, StatutPeriodePaiement::VALIDEE);

        $this->assertNull(PeriodePayabilityChecker::reasonPartNotPayable($part));
    }

    // ── touchedUntilAmount() ───────────────────────────────────────────────────

    public function test_touched_until_amount_ne_retient_que_les_items_necessaires(): void
    {
        $items = collect([
            (object) ['id' => 1, 'reste' => 1000.0],
            (object) ['id' => 2, 'reste' => 2000.0],
            (object) ['id' => 3, 'reste' => 500.0],
        ]);

        $touched = PeriodePayabilityChecker::touchedUntilAmount($items, 2500.0, fn ($i) => $i->reste);

        $this->assertCount(2, $touched);
        $this->assertEquals([1, 2], $touched->pluck('id')->all());
    }

    public function test_touched_until_amount_retient_tout_si_montant_couvre_tout(): void
    {
        $items = collect([
            (object) ['id' => 1, 'reste' => 1000.0],
            (object) ['id' => 2, 'reste' => 2000.0],
        ]);

        $touched = PeriodePayabilityChecker::touchedUntilAmount($items, 3000.0, fn ($i) => $i->reste);

        $this->assertCount(2, $touched);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makePaiementPeriode(StatutPeriodePaiement $statut): PaiementPeriode
    {
        $org = Organization::factory()->create();

        return PaiementPeriode::create([
            'organization_id' => $org->id,
            'reference' => 'PAY-202606-P1-LIV-'.uniqid(),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => $statut->value,
        ]);
    }

    private function validerPeriode(Organization $org, TypePeriodePaiement $type, $date, StatutPeriodePaiement $statut): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($org->id, $type, Carbon::parse($date));
        $periode->update(['statut' => $statut]);

        return $periode->fresh();
    }

    /** @return array{org: Organization, vehicule: Vehicule, livreur: Livreur, commission: CommissionLogistique, part: CommissionLogistiquePart} */
    private function makeCommissionLogistiqueScenario(): array
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Test',
        ]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'cloture',
            'created_by' => $user->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 3000,
            'montant_total' => 3000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $part = CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->prenom.' '.$livreur->nom,
            'taux_commission' => 60,
            'montant_brut' => 3000,
            'frais_supplementaires' => 0,
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => now()->subDays(15)->toDateString(),
        ]);

        return compact('org', 'vehicule', 'livreur', 'commission', 'part');
    }
}
