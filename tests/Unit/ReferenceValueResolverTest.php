<?php

namespace Tests\Unit;

use App\Services\ImportFlotte\Normalizers\ReferenceValueResolver;
use PHPUnit\Framework\TestCase;

class ReferenceValueResolverTest extends TestCase
{
    private function candidate(string $nom, ?string $code = null): object
    {
        return (object) ['nom' => $nom, 'code' => $code];
    }

    public function test_matches_exact_ignoring_case(): void
    {
        $candidates = [$this->candidate('Tricycle-70')];
        $match = ReferenceValueResolver::matchExact('TRICYCLE-70', $candidates, fn ($c) => $c->nom);

        $this->assertNotNull($match);
        $this->assertSame('Tricycle-70', $match->nom);
    }

    public function test_matches_exact_ignoring_accents_and_spaces_around_dash(): void
    {
        $candidates = [$this->candidate('Tricycle-70')];
        $match = ReferenceValueResolver::matchExact('tricycle - 70', $candidates, fn ($c) => $c->nom);

        $this->assertNotNull($match);
    }

    public function test_matches_exact_ignoring_dash_presence(): void
    {
        // Import réel : les valeurs collées sans tiret ("Tricycle75",
        // "Tricycle100") doivent matcher les types stockés avec tiret
        // ("Tricycle-75", "Tricycle-100") sans lever d'erreur ni de suggestion.
        $candidates = [$this->candidate('Tricycle-75')];
        $match = ReferenceValueResolver::matchExact('Tricycle75', $candidates, fn ($c) => $c->nom);

        $this->assertNotNull($match);
        $this->assertSame('Tricycle-75', $match->nom);
    }

    public function test_returns_null_when_no_candidate_matches(): void
    {
        $candidates = [$this->candidate('Tricycle-70')];
        $match = ReferenceValueResolver::matchExact('Camion XZ', $candidates, fn ($c) => $c->nom);

        $this->assertNull($match);
    }

    public function test_returns_null_when_match_is_ambiguous(): void
    {
        $candidates = [$this->candidate('Tricycle-70'), $this->candidate('TRICYCLE-70 ')];
        $match = ReferenceValueResolver::matchExact('Tricycle-70', $candidates, fn ($c) => $c->nom);

        $this->assertNull($match);
    }

    public function test_matches_via_extra_numeric_key_when_unique(): void
    {
        $candidates = [$this->candidate('Matoto', '01')];
        $match = ReferenceValueResolver::matchExact(
            '1',
            $candidates,
            fn ($c) => $c->nom,
            [fn ($c) => $c->code]
        );

        $this->assertNotNull($match);
        $this->assertSame('01', $match->code);
    }

    public function test_matches_against_multiple_label_fields(): void
    {
        $candidates = [$this->candidate('Matoto', '01'), $this->candidate('Kaloum', '02')];
        $match = ReferenceValueResolver::matchExact(
            '02',
            $candidates,
            [fn ($c) => $c->nom, fn ($c) => $c->code]
        );

        $this->assertNotNull($match);
        $this->assertSame('Kaloum', $match->nom);
    }

    public function test_suggest_closest_finds_unique_typo_match(): void
    {
        $candidates = [$this->candidate('Tricycle-70')];
        $suggestion = ReferenceValueResolver::suggestClosest('Ticyle-70', $candidates, fn ($c) => $c->nom);

        $this->assertSame('Tricycle-70', $suggestion);
    }

    public function test_suggest_closest_returns_null_when_ambiguous(): void
    {
        // "Tricycle-80" est à distance 1 des deux candidats (un seul chiffre
        // diffère dans chaque cas) : aucune suggestion ne doit être retenue.
        $candidates = [$this->candidate('Tricycle-70'), $this->candidate('Tricycle-90')];
        $suggestion = ReferenceValueResolver::suggestClosest('Tricycle-80', $candidates, fn ($c) => $c->nom);

        $this->assertNull($suggestion);
    }

    public function test_suggest_closest_picks_unique_nearest_even_with_other_close_candidates(): void
    {
        // Cas réel constaté en usage (fichier d'import Guinée) : le seeder crée
        // Tricycle-70/75/80/90/100. Une faute de frappe sur "70" reste à
        // distance 2 du bon candidat mais seulement à distance 3 des autres
        // variantes — ce n'est pas une égalité, donc pas une ambiguïté : la
        // suggestion doit être renvoyée malgré la présence de types voisins.
        $candidates = [
            $this->candidate('Tricycle-70'),
            $this->candidate('Tricycle-75'),
            $this->candidate('Tricycle-80'),
            $this->candidate('Tricycle-90'),
            $this->candidate('Tricycle-100'),
        ];
        $suggestion = ReferenceValueResolver::suggestClosest('Ticyle-70', $candidates, fn ($c) => $c->nom);

        $this->assertSame('Tricycle-70', $suggestion);
    }

    public function test_suggest_closest_returns_null_when_too_far(): void
    {
        $candidates = [$this->candidate('Tricycle-70')];
        $suggestion = ReferenceValueResolver::suggestClosest('Camion XZ', $candidates, fn ($c) => $c->nom);

        $this->assertNull($suggestion);
    }

    public function test_normalize_numeric_code(): void
    {
        $this->assertSame('1', ReferenceValueResolver::normalizeNumericCode('01'));
        $this->assertSame('1', ReferenceValueResolver::normalizeNumericCode('1'));
        $this->assertNull(ReferenceValueResolver::normalizeNumericCode('RC-01'));
    }
}
