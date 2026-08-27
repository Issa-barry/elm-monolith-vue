<?php

namespace Tests\Unit;

use App\Enums\KpiEvolutionDirection;
use App\Services\Client\KpiEvolutionCalculator;
use Tests\TestCase;

/**
 * Couvre la formule centrale de comparaison de période (cf. rapport du
 * 27/08/2026, point 22) — purement mathématique, sans base de données.
 */
class KpiEvolutionCalculatorTest extends TestCase
{
    public function test_current_greater_than_previous_is_a_positive_percent_up(): void
    {
        $evolution = KpiEvolutionCalculator::compare(120.0, 100.0);

        $this->assertSame(100.0, $evolution->previousValue);
        $this->assertSame(20.0, $evolution->percent);
        $this->assertSame(KpiEvolutionDirection::UP, $evolution->direction);
        $this->assertTrue($evolution->comparable);
    }

    public function test_current_lower_than_previous_is_a_negative_percent_down(): void
    {
        $evolution = KpiEvolutionCalculator::compare(80.0, 100.0);

        $this->assertSame(-20.0, $evolution->percent);
        $this->assertSame(KpiEvolutionDirection::DOWN, $evolution->direction);
        $this->assertTrue($evolution->comparable);
    }

    public function test_equal_values_are_stable_at_zero_percent(): void
    {
        $evolution = KpiEvolutionCalculator::compare(100.0, 100.0);

        $this->assertSame(0.0, $evolution->percent);
        $this->assertSame(KpiEvolutionDirection::STABLE, $evolution->direction);
        $this->assertTrue($evolution->comparable);
    }

    public function test_both_zero_is_stable_and_comparable_not_a_division_by_zero(): void
    {
        $evolution = KpiEvolutionCalculator::compare(0.0, 0.0);

        $this->assertSame(0.0, $evolution->previousValue);
        $this->assertSame(0.0, $evolution->percent);
        $this->assertSame(KpiEvolutionDirection::STABLE, $evolution->direction);
        $this->assertTrue($evolution->comparable);
    }

    /**
     * previous=0, current>0 : le pourcentage n'est pas défini mathématiquement.
     * Jamais Infinity/999999/100 en substitut — percent devient null et
     * comparable devient false, mais direction reste factuellement "up".
     */
    public function test_previous_zero_with_positive_current_is_not_comparable(): void
    {
        $evolution = KpiEvolutionCalculator::compare(100.0, 0.0);

        $this->assertSame(0.0, $evolution->previousValue);
        $this->assertNull($evolution->percent);
        $this->assertSame(KpiEvolutionDirection::UP, $evolution->direction);
        $this->assertFalse($evolution->comparable);
    }

    public function test_current_zero_with_positive_previous_is_a_full_negative_percent(): void
    {
        $evolution = KpiEvolutionCalculator::compare(0.0, 100.0);

        $this->assertSame(100.0, $evolution->previousValue);
        $this->assertSame(-100.0, $evolution->percent);
        $this->assertSame(KpiEvolutionDirection::DOWN, $evolution->direction);
        $this->assertTrue($evolution->comparable);
    }

    public function test_percent_is_rounded_to_one_decimal(): void
    {
        $evolution = KpiEvolutionCalculator::compare(112.34678, 100.0);

        $this->assertSame(12.3, $evolution->percent);
    }

    public function test_percent_rounding_does_not_produce_spurious_decimals(): void
    {
        $evolution = KpiEvolutionCalculator::compare(104.0, 100.0);

        $this->assertSame(4.0, $evolution->percent);
    }
}
