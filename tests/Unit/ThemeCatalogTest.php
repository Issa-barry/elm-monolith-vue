<?php

namespace Tests\Unit;

use App\Support\Theming\ThemeCatalog;
use Tests\TestCase;

class ThemeCatalogTest extends TestCase
{
    public function test_parse_list_returns_full_catalog_when_env_value_is_null(): void
    {
        $this->assertSame(
            ThemeCatalog::PRIMARIES,
            ThemeCatalog::parseList(null, ThemeCatalog::PRIMARIES),
        );
    }

    public function test_parse_list_returns_full_catalog_when_env_value_is_blank(): void
    {
        $this->assertSame(
            ThemeCatalog::PRIMARIES,
            ThemeCatalog::parseList('   ', ThemeCatalog::PRIMARIES),
        );
    }

    public function test_parse_list_filters_to_known_catalog_values(): void
    {
        $result = ThemeCatalog::parseList('emerald,green,orange', ThemeCatalog::PRIMARIES);

        $this->assertSame(['emerald', 'green', 'orange'], $result);
    }

    public function test_parse_list_trims_and_lowercases_values(): void
    {
        $result = ThemeCatalog::parseList(' Emerald , GREEN ,orange ', ThemeCatalog::PRIMARIES);

        $this->assertSame(['emerald', 'green', 'orange'], $result);
    }

    public function test_parse_list_drops_unknown_values_silently(): void
    {
        $result = ThemeCatalog::parseList('emerald,not-a-color,orange', ThemeCatalog::PRIMARIES);

        $this->assertSame(['emerald', 'orange'], $result);
    }

    /**
     * Filet de sécurité : une politique mal configurée (typos partout, valeurs
     * inconnues) ne doit jamais aboutir à "aucune valeur autorisée".
     */
    public function test_parse_list_falls_back_to_full_catalog_when_nothing_valid_remains(): void
    {
        $result = ThemeCatalog::parseList('not-a-color,also-invalid', ThemeCatalog::PRIMARIES);

        $this->assertSame(ThemeCatalog::PRIMARIES, $result);
    }

    public function test_parse_list_deduplicates_values(): void
    {
        $result = ThemeCatalog::parseList('emerald,emerald,green', ThemeCatalog::PRIMARIES);

        $this->assertSame(['emerald', 'green'], $result);
    }

    public function test_blue_family_is_a_subset_of_primaries(): void
    {
        foreach (ThemeCatalog::BLUE_FAMILY as $color) {
            $this->assertContains($color, ThemeCatalog::PRIMARIES);
        }
    }

    public function test_fallback_values_are_within_their_own_catalogs(): void
    {
        $this->assertContains(ThemeCatalog::FALLBACK_PRESET, ThemeCatalog::PRESETS);
        $this->assertContains(ThemeCatalog::FALLBACK_PRIMARY, ThemeCatalog::PRIMARIES);
        $this->assertContains(ThemeCatalog::FALLBACK_SURFACE, ThemeCatalog::SURFACES);
    }
}
