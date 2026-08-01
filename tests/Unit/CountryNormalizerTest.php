<?php

namespace Tests\Unit;

use App\Services\ImportFlotte\Normalizers\CountryNormalizer;
use PHPUnit\Framework\TestCase;

class CountryNormalizerTest extends TestCase
{
    public function test_resolves_exact_alpha2_code(): void
    {
        $this->assertSame('GN', CountryNormalizer::resolve('GN'));
    }

    public function test_resolves_lowercase_alpha2_code(): void
    {
        $this->assertSame('GN', CountryNormalizer::resolve('gn'));
    }

    public function test_resolves_country_name_uppercase_with_accent(): void
    {
        $this->assertSame('GN', CountryNormalizer::resolve('GUINÉE'));
    }

    public function test_resolves_country_name_without_accent(): void
    {
        $this->assertSame('GN', CountryNormalizer::resolve('guinee'));
    }

    public function test_resolves_country_name_with_surrounding_spaces(): void
    {
        $this->assertSame('GN', CountryNormalizer::resolve('  Guinée  '));
    }

    public function test_resolves_alpha3_code(): void
    {
        $this->assertSame('GN', CountryNormalizer::resolve('GIN'));
    }

    public function test_resolves_foreign_country_name(): void
    {
        $this->assertSame('BE', CountryNormalizer::resolve('BELGIQUE'));
        $this->assertSame('BE', CountryNormalizer::resolve('Belgique'));
        $this->assertSame('BE', CountryNormalizer::resolve('BEL'));
    }

    public function test_returns_null_for_unknown_country(): void
    {
        $this->assertNull(CountryNormalizer::resolve('Narnia'));
    }

    public function test_returns_null_for_empty_value(): void
    {
        $this->assertNull(CountryNormalizer::resolve(''));
        $this->assertNull(CountryNormalizer::resolve('   '));
    }
}
