<?php

namespace Tests\Unit;

use App\Services\CommissionSearchService;
use PHPUnit\Framework\TestCase;

class CommissionSearchServiceTest extends TestCase
{
    // ── normalizeText ─────────────────────────────────────────────────────────

    public function test_normalize_text_lowercases(): void
    {
        $this->assertSame('hello world', CommissionSearchService::normalizeText('Hello World'));
    }

    public function test_normalize_text_strips_accents(): void
    {
        $this->assertSame('impaye', CommissionSearchService::normalizeText('impayé'));
        $this->assertSame('paye', CommissionSearchService::normalizeText('payé'));
        $this->assertSame('eleonore', CommissionSearchService::normalizeText('Éléonore'));
        $this->assertSame('ba', CommissionSearchService::normalizeText('Bâ'));
        $this->assertSame('c', CommissionSearchService::normalizeText('ç'));
    }

    public function test_normalize_text_handles_empty_string(): void
    {
        $this->assertSame('', CommissionSearchService::normalizeText(''));
    }

    // ── normalizePhoneDigits ──────────────────────────────────────────────────

    public function test_normalize_phone_digits_strips_spaces_and_plus(): void
    {
        $this->assertSame('224622000012', CommissionSearchService::normalizePhoneDigits('+224 622 000 012'));
    }

    public function test_normalize_phone_digits_plain_number(): void
    {
        $this->assertSame('622000012', CommissionSearchService::normalizePhoneDigits('622000012'));
    }

    public function test_normalize_phone_digits_compact_international(): void
    {
        $this->assertSame('224622000012', CommissionSearchService::normalizePhoneDigits('+224622000012'));
    }

    public function test_normalize_phone_digits_returns_empty_for_letters(): void
    {
        $this->assertSame('', CommissionSearchService::normalizePhoneDigits('abc'));
    }

    // ── parseAmountQuery ──────────────────────────────────────────────────────

    public function test_parse_amount_plain_number(): void
    {
        $this->assertSame(4800, CommissionSearchService::parseAmountQuery('4800'));
    }

    public function test_parse_amount_with_space(): void
    {
        $this->assertSame(4800, CommissionSearchService::parseAmountQuery('4 800'));
    }

    public function test_parse_amount_with_gnf_suffix(): void
    {
        $this->assertSame(4800, CommissionSearchService::parseAmountQuery('4800 GNF'));
        $this->assertSame(4800, CommissionSearchService::parseAmountQuery('4 800 GNF'));
        $this->assertSame(4800, CommissionSearchService::parseAmountQuery('4800gnf'));
    }

    public function test_parse_amount_returns_null_for_text(): void
    {
        $this->assertNull(CommissionSearchService::parseAmountQuery('Mamadou'));
        $this->assertNull(CommissionSearchService::parseAmountQuery(''));
        $this->assertNull(CommissionSearchService::parseAmountQuery('impayé'));
    }

    public function test_parse_amount_returns_null_for_mixed_alphanum(): void
    {
        $this->assertNull(CommissionSearchService::parseAmountQuery('48x00'));
        $this->assertNull(CommissionSearchService::parseAmountQuery('RC-001-GN'));
    }

    // ── matches ───────────────────────────────────────────────────────────────

    private function livreur(array $overrides = []): array
    {
        return array_merge([
            'nom'       => 'Mamadou Barry',
            'telephone' => '+224622000012',
            'vehicules' => 'Camion Test RC-001-GN',
            'impaye'    => 4800.0,
            'paye'      => 0.0,
        ], $overrides);
    }

    public function test_matches_empty_search_returns_true(): void
    {
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), ''));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), '   '));
    }

    public function test_matches_by_name_case_insensitive(): void
    {
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'mamadou'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'BARRY'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'mam'));
    }

    public function test_matches_by_name_accent_insensitive(): void
    {
        $livreur = $this->livreur(['nom' => 'Éléonore Bâ']);
        $this->assertTrue(CommissionSearchService::matches($livreur, 'eleonore'));
        $this->assertTrue(CommissionSearchService::matches($livreur, 'ba'));
        // search with accent must also work (normalized both sides)
        $this->assertTrue(CommissionSearchService::matches($livreur, 'Éléonore'));
    }

    public function test_matches_by_vehicule_nom(): void
    {
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'Camion'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'camion test'));
    }

    public function test_matches_by_immatriculation(): void
    {
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'RC-001-GN'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'rc-001'));
    }

    public function test_matches_by_phone_various_formats(): void
    {
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), '622000012'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), '+224622000012'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), '224 622 000 012'));
    }

    public function test_matches_by_amount_impaye(): void
    {
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), '4800'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), '4 800'));
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), '4800 GNF'));
    }

    public function test_matches_by_amount_total(): void
    {
        $l = $this->livreur(['impaye' => 3000.0, 'paye' => 1800.0]);
        $this->assertTrue(CommissionSearchService::matches($l, '4800'));  // total = 4800
        $this->assertTrue(CommissionSearchService::matches($l, '3000'));  // impaye
        $this->assertTrue(CommissionSearchService::matches($l, '1800'));  // paye
    }

    public function test_matches_statut_impaye_keyword(): void
    {
        $l = $this->livreur(['impaye' => 4800.0, 'paye' => 0.0]);
        $this->assertTrue(CommissionSearchService::matches($l, 'impayé'));
        $this->assertTrue(CommissionSearchService::matches($l, 'impaye'));
        // "payé" doit échouer car impaye > 0
        $this->assertFalse(CommissionSearchService::matches($l, 'payé'));
    }

    public function test_matches_statut_paye_keyword(): void
    {
        $l = $this->livreur(['impaye' => 0.0, 'paye' => 2000.0]);
        $this->assertTrue(CommissionSearchService::matches($l, 'payé'));
        $this->assertTrue(CommissionSearchService::matches($l, 'paye'));
        $this->assertFalse(CommissionSearchService::matches($l, 'impayé'));
    }

    public function test_matches_statut_partiel_keyword(): void
    {
        // a du paye ET de l'impaye → partiel
        $l = $this->livreur(['impaye' => 1500.0, 'paye' => 1000.0]);
        $this->assertTrue(CommissionSearchService::matches($l, 'partiel'));

        // uniquement impaye → pas partiel
        $l2 = $this->livreur(['impaye' => 1500.0, 'paye' => 0.0]);
        $this->assertFalse(CommissionSearchService::matches($l2, 'partiel'));
    }

    public function test_matches_multi_token_all_must_match(): void
    {
        // "Mamadou 622" → nom=Mamadou ✓  tel=622... ✓
        $this->assertTrue(CommissionSearchService::matches($this->livreur(), 'Mamadou 622'));
        // "Ibrahima 622" → nom ne contient pas Ibrahima → false
        $this->assertFalse(CommissionSearchService::matches($this->livreur(), 'Ibrahima 622'));
    }

    public function test_matches_null_phone_does_not_crash(): void
    {
        $l = $this->livreur(['telephone' => null]);
        $this->assertFalse(CommissionSearchService::matches($l, '622000012'));
        $this->assertTrue(CommissionSearchService::matches($l, 'Mamadou'));
    }

    public function test_matches_null_vehicules_does_not_crash(): void
    {
        $l = $this->livreur(['vehicules' => null]);
        $this->assertFalse(CommissionSearchService::matches($l, 'RC-001'));
        $this->assertTrue(CommissionSearchService::matches($l, 'Mamadou'));
    }

    public function test_no_match_returns_false(): void
    {
        $this->assertFalse(CommissionSearchService::matches($this->livreur(), 'Inexistant'));
        $this->assertFalse(CommissionSearchService::matches($this->livreur(), '99999999'));
    }

    // ── filter ────────────────────────────────────────────────────────────────

    public function test_filter_empty_search_returns_all(): void
    {
        $l = collect([$this->livreur(), $this->livreur(['nom' => 'Alpha Bah'])]);
        $this->assertCount(2, CommissionSearchService::filter($l, ''));
        $this->assertCount(2, CommissionSearchService::filter($l, '   '));
    }

    public function test_filter_returns_matching_subset(): void
    {
        $col = collect([
            $this->livreur(['nom' => 'Mamadou Barry']),
            $this->livreur(['nom' => 'Ibrahima Diallo', 'telephone' => '+224633000002']),
        ]);
        $result = CommissionSearchService::filter($col, 'Mamadou');
        $this->assertCount(1, $result);
        $this->assertSame('Mamadou Barry', $result->first()['nom']);
    }
}
