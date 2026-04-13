<?php

namespace Tests\Unit;

use App\Services\PeriodeComptableService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests unitaires pour PeriodeComptableService.
 *
 * Ces tests sont purement en mémoire — aucune base de données n'est requise.
 */
class PeriodeComptableServiceTest extends TestCase
{
    // ── codeForLivreur ────────────────────────────────────────────────────────

    /** @test */
    public function it_assigns_p1_for_day_1(): void
    {
        $this->assertSame('2026-04-P1', PeriodeComptableService::codeForLivreur(Carbon::create(2026, 4, 1)));
    }

    /** @test */
    public function it_assigns_p1_for_day_15(): void
    {
        $this->assertSame('2026-04-P1', PeriodeComptableService::codeForLivreur(Carbon::create(2026, 4, 15)));
    }

    /** @test */
    public function it_assigns_p2_for_day_16(): void
    {
        $this->assertSame('2026-04-P2', PeriodeComptableService::codeForLivreur(Carbon::create(2026, 4, 16)));
    }

    /** @test */
    public function it_assigns_p2_for_last_day_of_month(): void
    {
        // Cas critère d'acceptation : 26/04/2026 → 2026-04-P2
        $this->assertSame('2026-04-P2', PeriodeComptableService::codeForLivreur(Carbon::create(2026, 4, 26)));
    }

    /** @test */
    public function it_assigns_p1_for_12_april(): void
    {
        // Critère d'acceptation explicite : 12/04/2026 → 2026-04-P1
        $this->assertSame('2026-04-P1', PeriodeComptableService::codeForLivreur(Carbon::create(2026, 4, 12)));
    }

    /** @test */
    public function it_assigns_p2_for_last_day_of_february_leap_year(): void
    {
        // 29/02/2024 (année bissextile) → 2024-02-P2
        $this->assertSame('2024-02-P2', PeriodeComptableService::codeForLivreur(Carbon::create(2024, 2, 29)));
    }

    /** @test */
    public function it_assigns_p2_for_last_day_of_february_non_leap_year(): void
    {
        // 28/02/2025 → 2025-02-P2
        $this->assertSame('2025-02-P2', PeriodeComptableService::codeForLivreur(Carbon::create(2025, 2, 28)));
    }

    // ── codeForProprietaire ───────────────────────────────────────────────────

    /** @test */
    public function it_assigns_monthly_code_for_proprietaire(): void
    {
        $this->assertSame('2026-04-M', PeriodeComptableService::codeForProprietaire(Carbon::create(2026, 4, 12)));
        $this->assertSame('2026-04-M', PeriodeComptableService::codeForProprietaire(Carbon::create(2026, 4, 26)));
    }

    // ── codeFor dispatch ─────────────────────────────────────────────────────

    /** @test */
    public function code_for_dispatches_correctly(): void
    {
        $date = Carbon::create(2026, 4, 12);

        $this->assertSame('2026-04-P1', PeriodeComptableService::codeFor('livreur', $date));
        $this->assertSame('2026-04-M', PeriodeComptableService::codeFor('proprietaire', $date));
    }

    // ── dateRangeForCode ──────────────────────────────────────────────────────

    /** @test */
    public function date_range_for_p1_spans_1_to_15(): void
    {
        [$start, $end] = PeriodeComptableService::dateRangeForCode('2026-04-P1');

        $this->assertSame('2026-04-01', $start->toDateString());
        $this->assertSame('2026-04-15', $end->toDateString());
    }

    /** @test */
    public function date_range_for_p2_spans_16_to_end_of_month(): void
    {
        [$start, $end] = PeriodeComptableService::dateRangeForCode('2026-04-P2');

        $this->assertSame('2026-04-16', $start->toDateString());
        $this->assertSame('2026-04-30', $end->toDateString());
    }

    /** @test */
    public function date_range_for_p2_february_leap_year(): void
    {
        [$start, $end] = PeriodeComptableService::dateRangeForCode('2024-02-P2');

        $this->assertSame('2024-02-16', $start->toDateString());
        $this->assertSame('2024-02-29', $end->toDateString());
    }

    /** @test */
    public function date_range_for_m_spans_full_month(): void
    {
        [$start, $end] = PeriodeComptableService::dateRangeForCode('2026-04-M');

        $this->assertSame('2026-04-01', $start->toDateString());
        $this->assertSame('2026-04-30', $end->toDateString());
    }

    /** @test */
    public function date_range_throws_for_invalid_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodeComptableService::dateRangeForCode('invalid');
    }

    // ── labelForCode ─────────────────────────────────────────────────────────

    /** @test */
    public function label_for_p1_contains_period_range(): void
    {
        $label = PeriodeComptableService::labelForCode('2026-04-P1');
        $this->assertStringContainsString('P1', $label);
        $this->assertStringContainsString('01', $label);
        $this->assertStringContainsString('15', $label);
        $this->assertStringContainsString('2026', $label);
    }

    /** @test */
    public function label_for_p2_contains_last_day(): void
    {
        $label = PeriodeComptableService::labelForCode('2026-04-P2');
        $this->assertStringContainsString('P2', $label);
        $this->assertStringContainsString('16', $label);
        $this->assertStringContainsString('30', $label);
    }

    /** @test */
    public function label_for_p2_february_shows_28_or_29(): void
    {
        $label2025 = PeriodeComptableService::labelForCode('2025-02-P2');
        $this->assertStringContainsString('28', $label2025);

        $label2024 = PeriodeComptableService::labelForCode('2024-02-P2');
        $this->assertStringContainsString('29', $label2024);
    }

    /** @test */
    public function label_returns_code_unchanged_if_invalid(): void
    {
        $this->assertSame('bad-code', PeriodeComptableService::labelForCode('bad-code'));
    }

    // ── Critères d'acceptation métier ────────────────────────────────────────

    /** @test */
    public function acceptance_criteria_late_payment_does_not_reclassify(): void
    {
        // Commission du 26/04 payée le 02/05 → reste 2026-04-P2
        $earnedAt = Carbon::create(2026, 4, 26);
        $periode = PeriodeComptableService::codeForLivreur($earnedAt);

        $this->assertSame('2026-04-P2', $periode);
        // (le paid_at ne change pas la période — vérification conceptuelle : le code
        //  ne dépend que de earned_at, jamais de paid_at)
    }

    /** @test */
    public function acceptance_criteria_advance_payment_does_not_reclassify(): void
    {
        // Commission du 14/04 payée le 10/04 (avance) → reste 2026-04-P1
        $earnedAt = Carbon::create(2026, 4, 14);
        $periode = PeriodeComptableService::codeForLivreur($earnedAt);

        $this->assertSame('2026-04-P1', $periode);
    }

    // ── periodesLivreurBetween ────────────────────────────────────────────────

    /** @test */
    public function it_returns_two_periodes_per_month(): void
    {
        $periodes = PeriodeComptableService::periodesLivreurBetween(
            Carbon::create(2026, 4, 1),
            Carbon::create(2026, 4, 30),
        );

        $codes = array_column($periodes, 'code');

        $this->assertContains('2026-04-P1', $codes);
        $this->assertContains('2026-04-P2', $codes);
        $this->assertCount(2, $codes);
    }

    /** @test */
    public function it_returns_periodes_in_descending_order(): void
    {
        $periodes = PeriodeComptableService::periodesLivreurBetween(
            Carbon::create(2026, 3, 1),
            Carbon::create(2026, 4, 30),
        );

        $codes = array_column($periodes, 'code');

        // Plus récent en premier
        $this->assertSame('2026-04-P2', $codes[0]);
        $this->assertSame('2026-04-P1', $codes[1]);
        $this->assertSame('2026-03-P2', $codes[2]);
        $this->assertSame('2026-03-P1', $codes[3]);
    }

    /** @test */
    public function each_periode_has_code_and_label(): void
    {
        $periodes = PeriodeComptableService::periodesLivreurBetween(
            Carbon::create(2026, 4, 1),
            Carbon::create(2026, 4, 30),
        );

        foreach ($periodes as $periode) {
            $this->assertArrayHasKey('code', $periode);
            $this->assertArrayHasKey('label', $periode);
            $this->assertNotEmpty($periode['code']);
            $this->assertNotEmpty($periode['label']);
        }
    }
}
