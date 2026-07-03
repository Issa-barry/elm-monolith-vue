<?php

namespace Tests\Unit;

use App\Enums\TypePeriodePaiement;
use App\Services\PeriodePaiementService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests unitaires purement en mémoire pour la partie calcul de PeriodePaiementService
 * (quinzaine, plage de dates, référence, libellé) — aucune base de données requise.
 */
class PeriodePaiementServiceTest extends TestCase
{
    // ── quinzaineForDate ──────────────────────────────────────────────────────

    public function test_jour_1_est_p1(): void
    {
        $this->assertSame('P1', PeriodePaiementService::quinzaineForDate(Carbon::create(2026, 7, 1)));
    }

    public function test_jour_15_est_p1(): void
    {
        $this->assertSame('P1', PeriodePaiementService::quinzaineForDate(Carbon::create(2026, 7, 15)));
    }

    public function test_jour_16_est_p2(): void
    {
        $this->assertSame('P2', PeriodePaiementService::quinzaineForDate(Carbon::create(2026, 7, 16)));
    }

    public function test_dernier_jour_du_mois_est_p2(): void
    {
        $this->assertSame('P2', PeriodePaiementService::quinzaineForDate(Carbon::create(2026, 7, 31)));
    }

    // ── dateRangeFor ──────────────────────────────────────────────────────────

    public function test_plage_p1_juillet_2026(): void
    {
        [$debut, $fin] = PeriodePaiementService::dateRangeFor(2026, 7, 'P1');
        $this->assertSame('2026-07-01', $debut->toDateString());
        $this->assertSame('2026-07-15', $fin->toDateString());
    }

    public function test_plage_p2_juillet_2026(): void
    {
        [$debut, $fin] = PeriodePaiementService::dateRangeFor(2026, 7, 'P2');
        $this->assertSame('2026-07-16', $debut->toDateString());
        $this->assertSame('2026-07-31', $fin->toDateString());
    }

    public function test_plage_p2_fevrier_2026_sarrete_au_28(): void
    {
        [, $fin] = PeriodePaiementService::dateRangeFor(2026, 2, 'P2');
        $this->assertSame('2026-02-28', $fin->toDateString());
    }

    public function test_quinzaine_invalide_leve_une_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodePaiementService::dateRangeFor(2026, 7, 'P3');
    }

    // ── referenceFor ──────────────────────────────────────────────────────────

    public function test_reference_livreur_p1(): void
    {
        $this->assertSame(
            'PAY-202607-P1-LIV',
            PeriodePaiementService::referenceFor(TypePeriodePaiement::LIVREUR, 2026, 7, 'P1'),
        );
    }

    public function test_reference_proprietaire_p1(): void
    {
        $this->assertSame(
            'PAY-202607-P1-PRO',
            PeriodePaiementService::referenceFor(TypePeriodePaiement::PROPRIETAIRE, 2026, 7, 'P1'),
        );
    }

    public function test_reference_salarie_p2(): void
    {
        $this->assertSame(
            'PAY-202607-P2-SAL',
            PeriodePaiementService::referenceFor(TypePeriodePaiement::SALARIE, 2026, 7, 'P2'),
        );
    }

    // ── labelFor ──────────────────────────────────────────────────────────────

    public function test_label_juillet_p1(): void
    {
        $this->assertSame('Juillet 2026 - P1', PeriodePaiementService::labelFor(2026, 7, 'P1'));
    }
}
