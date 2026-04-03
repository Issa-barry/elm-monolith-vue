<?php

namespace Tests\Unit;

use App\Support\AppVersion;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    public function test_label_returns_string_with_version_prefix(): void
    {
        $label = AppVersion::label();
        $this->assertIsString($label);
        $this->assertStringStartsWith('V: ', $label);
    }

    public function test_current_returns_non_empty_string(): void
    {
        $version = AppVersion::current();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }

    public function test_current_is_consistent_on_repeated_calls(): void
    {
        $first = AppVersion::current();
        $second = AppVersion::current();
        $this->assertSame($first, $second);
    }

    public function test_label_is_release_aligned_without_runtime_timestamp(): void
    {
        $label = AppVersion::label();
        $this->assertStringNotContainsString(' le ', $label);
        $this->assertStringNotContainsString(' a ', $label);
    }
}
