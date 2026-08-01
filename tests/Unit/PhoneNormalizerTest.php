<?php

namespace Tests\Unit;

use App\Services\ImportFlotte\Normalizers\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PhoneNormalizer;
    }

    public function test_bare_local_number(): void
    {
        $result = $this->normalizer->normalize('622000008', 'GN');
        $this->assertSame('+224622000008', $result['telephone']);
        $this->assertNull($result['erreur']);
    }

    public function test_number_with_leading_zero(): void
    {
        $result = $this->normalizer->normalize('0622000008', 'GN');
        $this->assertSame('+224622000008', $result['telephone']);
    }

    public function test_number_with_country_code_no_plus(): void
    {
        $result = $this->normalizer->normalize('224622000008', 'GN');
        $this->assertSame('+224622000008', $result['telephone']);
    }

    public function test_number_with_plus_prefix(): void
    {
        $result = $this->normalizer->normalize('+224622000008', 'GN');
        $this->assertSame('+224622000008', $result['telephone']);
    }

    public function test_number_with_00_prefix(): void
    {
        $result = $this->normalizer->normalize('00224622000008', 'GN');
        $this->assertSame('+224622000008', $result['telephone']);
    }

    public function test_number_with_spaces_and_dashes(): void
    {
        $this->assertSame('+224622000008', $this->normalizer->normalize('622 00 00 08', 'GN')['telephone']);
        $this->assertSame('+224622000008', $this->normalizer->normalize('622-00-00-08', 'GN')['telephone']);
    }

    public function test_does_not_strip_prefix_when_it_would_produce_wrong_length(): void
    {
        // "224000008" est un numéro local guinéen de 9 chiffres qui commence
        // par les mêmes chiffres que l'indicatif : ne doit pas être tronqué.
        $result = $this->normalizer->normalize('224000008', 'GN');
        $this->assertSame('+224224000008', $result['telephone']);
    }

    public function test_foreign_number_uses_country_dial_code(): void
    {
        $result = $this->normalizer->normalize('476123456', 'BE');
        $this->assertSame('+32476123456', $result['telephone']);
    }

    public function test_invalid_length_returns_explicit_error_without_inventing_number(): void
    {
        $result = $this->normalizer->normalize('12345', 'GN');
        $this->assertNull($result['telephone']);
        $this->assertNotNull($result['erreur']);
    }

    public function test_empty_value_returns_error(): void
    {
        $result = $this->normalizer->normalize('', 'GN');
        $this->assertNull($result['telephone']);
        $this->assertNotNull($result['erreur']);
    }
}
