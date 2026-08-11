<?php

namespace Tests\Unit;

use App\Services\PhoneCountryInfo;
use Tests\TestCase;

class PhoneCountryInfoTest extends TestCase
{
    public function test_resolves_guinean_number(): void
    {
        $info = PhoneCountryInfo::resolve('+224622000000');

        $this->assertNotNull($info);
        $this->assertSame('+224622000000', $info['telephone']);
        $this->assertSame('GN', $info['code_pays']);
        $this->assertSame('+224', $info['indicatif']);
        $this->assertSame('Guinée', $info['pays']);
        $this->assertSame('GNF', $info['devise']);
        $this->assertSame('Africa/Conakry', $info['fuseau']);
    }

    public function test_resolves_french_number(): void
    {
        $info = PhoneCountryInfo::resolve('+33612345678');

        $this->assertNotNull($info);
        $this->assertSame('FR', $info['code_pays']);
        $this->assertSame('+33', $info['indicatif']);
        $this->assertSame('France', $info['pays']);
        $this->assertSame('EUR', $info['devise']);
    }

    public function test_returns_null_for_missing_plus_prefix(): void
    {
        $this->assertNull(PhoneCountryInfo::resolve('224622000000'));
    }

    public function test_returns_null_for_invalid_number(): void
    {
        $this->assertNull(PhoneCountryInfo::resolve('+1234'));
    }

    public function test_returns_null_for_garbage_input(): void
    {
        $this->assertNull(PhoneCountryInfo::resolve('not a phone number'));
    }
}
