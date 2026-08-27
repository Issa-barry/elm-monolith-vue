<?php

namespace Tests\Unit;

use App\Services\Client\ClientEarningsService;
use Tests\TestCase;

/**
 * Couvre `previousPeriodBounds()` — règle "période immédiatement précédente
 * de même durée" (cf. rapport du 27/08/2026, point 24). Purement calculatoire
 * (Carbon), sans base de données.
 */
class ClientEarningsServicePreviousPeriodBoundsTest extends TestCase
{
    private function service(): ClientEarningsService
    {
        return new ClientEarningsService;
    }

    public function test_full_calendar_month_compares_to_the_previous_full_month(): void
    {
        [$debut, $fin] = $this->service()->previousPeriodBounds('2026-08-01', '2026-08-31');

        $this->assertSame('2026-07-01', $debut);
        $this->assertSame('2026-07-31', $fin);
    }

    public function test_seven_day_window_compares_to_the_seven_days_immediately_before(): void
    {
        [$debut, $fin] = $this->service()->previousPeriodBounds('2026-08-10', '2026-08-16');

        $this->assertSame('2026-08-03', $debut);
        $this->assertSame('2026-08-09', $fin);
    }

    public function test_thirty_day_window_compares_to_the_thirty_days_immediately_before(): void
    {
        [$debut, $fin] = $this->service()->previousPeriodBounds('2026-08-01', '2026-08-30');

        $this->assertSame('2026-07-02', $debut);
        $this->assertSame('2026-07-31', $fin);
    }

    public function test_single_day_period_compares_to_the_single_day_before(): void
    {
        [$debut, $fin] = $this->service()->previousPeriodBounds('2026-08-15', '2026-08-15');

        $this->assertSame('2026-08-14', $debut);
        $this->assertSame('2026-08-14', $fin);
    }

    /**
     * Une période custom arbitraire (pas un mois complet) ne doit jamais être
     * comparée au "mois précédent" — seule la durée en jours compte.
     */
    public function test_custom_period_not_aligned_to_a_month_uses_the_same_day_count(): void
    {
        [$debut, $fin] = $this->service()->previousPeriodBounds('2026-08-05', '2026-08-12');

        $this->assertSame('2026-07-28', $debut);
        $this->assertSame('2026-08-04', $fin);
    }
}
