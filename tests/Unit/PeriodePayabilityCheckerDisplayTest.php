<?php

namespace Tests\Unit;

use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Organization;
use App\Models\PaiementPeriode;
use App\Services\PeriodePayabilityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre la table de décision statut-période -> statut affiché / payabilité,
 * utilisée pour aligner les badges et le bouton "Payer" sur le verrou backend
 * (voir PeriodePayabilityCheckerTest pour le verrou lui-même).
 */
class PeriodePayabilityCheckerDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function makePeriode(StatutPeriodePaiement $statut): PaiementPeriode
    {
        $org = Organization::factory()->create();

        return PaiementPeriode::create([
            'organization_id' => $org->id,
            'reference' => 'PAY-'.uniqid(),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => $statut->value,
        ]);
    }

    public function test_deja_paye_prime_sur_le_statut_de_periode(): void
    {
        // Même sans période (ou une période non validée), un montant déjà soldé
        // reste affiché "Payé" et jamais payable.
        $result = PeriodePayabilityChecker::statutAffichage(null, 'paye', 'Payé');

        $this->assertSame(['status' => 'paye', 'label' => 'Payé', 'payable' => false], $result);
    }

    public function test_aucune_periode_affiche_en_attente(): void
    {
        $result = PeriodePayabilityChecker::statutAffichage(null, 'impaye', 'Impayé');

        $this->assertSame('brouillon', $result['status']);
        $this->assertSame('En attente', $result['label']);
        $this->assertFalse($result['payable']);
    }

    public function test_periode_brouillon_affiche_en_attente(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON);

        $result = PeriodePayabilityChecker::statutAffichage($periode, 'impaye', 'Impayé');

        $this->assertSame('brouillon', $result['status']);
        $this->assertSame('En attente', $result['label']);
        $this->assertFalse($result['payable']);
    }

    public function test_periode_calculee_affiche_en_attente_de_validation(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::CALCULEE);

        $result = PeriodePayabilityChecker::statutAffichage($periode, 'partiel', 'Partiel');

        $this->assertSame('calculee', $result['status']);
        $this->assertSame('En attente de validation', $result['label']);
        $this->assertFalse($result['payable']);
    }

    public function test_periode_validee_impaye_est_payable(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::VALIDEE);

        $result = PeriodePayabilityChecker::statutAffichage($periode, 'impaye', 'Impayé');

        $this->assertSame('impaye', $result['status']);
        $this->assertSame('Impayé', $result['label']);
        $this->assertTrue($result['payable']);
    }

    public function test_periode_validee_partiel_est_payable(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::VALIDEE);

        $result = PeriodePayabilityChecker::statutAffichage($periode, 'partiel', 'Partiel');

        $this->assertSame('partiel', $result['status']);
        $this->assertTrue($result['payable']);
    }

    public function test_periode_cloturee_avec_reste_affiche_cloturee(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::CLOTUREE);

        $result = PeriodePayabilityChecker::statutAffichage($periode, 'impaye', 'Impayé');

        $this->assertSame('cloturee', $result['status']);
        $this->assertSame('Clôturée', $result['label']);
        $this->assertFalse($result['payable']);
    }

    public function test_periode_cloturee_soldee_affiche_paye(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::CLOTUREE);

        $result = PeriodePayabilityChecker::statutAffichage($periode, 'paye', 'Payé');

        $this->assertSame('paye', $result['status']);
        $this->assertFalse($result['payable']);
    }
}
