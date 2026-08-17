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

    // ── normalizeCodeKey() : clé de comparaison canonique pour les codes ────
    // Utilisée partout où un code de site est comparé (SiteImportParser) —
    // cf. matrice demandée : 001 = 01 = 1, 0001 = 1, 002 = 2, 010 = 10,
    // 000 = 0 (jamais vide), " 001 " = 1, et jamais de réduction abusive
    // d'un code alphanumérique.

    public function test_normalize_code_key_treats_leading_zero_variants_as_equal(): void
    {
        $key = ReferenceValueResolver::normalizeCodeKey('001');
        $this->assertSame($key, ReferenceValueResolver::normalizeCodeKey('01'));
        $this->assertSame($key, ReferenceValueResolver::normalizeCodeKey('1'));
        $this->assertSame($key, ReferenceValueResolver::normalizeCodeKey('0001'));
    }

    public function test_normalize_code_key_002_equals_2(): void
    {
        $this->assertSame(
            ReferenceValueResolver::normalizeCodeKey('002'),
            ReferenceValueResolver::normalizeCodeKey('2')
        );
    }

    public function test_normalize_code_key_only_strips_leading_zeros_not_trailing(): void
    {
        // "010" représente la valeur 10, pas 1 : seuls les zéros en PRÉFIXE
        // sont ignorés.
        $key010 = ReferenceValueResolver::normalizeCodeKey('010');
        $this->assertSame($key010, ReferenceValueResolver::normalizeCodeKey('10'));
        $this->assertNotSame($key010, ReferenceValueResolver::normalizeCodeKey('1'));
    }

    public function test_normalize_code_key_all_zeros_stays_functionally_zero_never_empty(): void
    {
        $key = ReferenceValueResolver::normalizeCodeKey('0');
        $this->assertNotSame('', $key);
        $this->assertSame($key, ReferenceValueResolver::normalizeCodeKey('00'));
        $this->assertSame($key, ReferenceValueResolver::normalizeCodeKey('000'));
    }

    public function test_normalize_code_key_trims_surrounding_whitespace(): void
    {
        $this->assertSame(
            ReferenceValueResolver::normalizeCodeKey('1'),
            ReferenceValueResolver::normalizeCodeKey(' 001 ')
        );
    }

    public function test_normalize_code_key_never_reduces_alphanumeric_code_to_its_digits(): void
    {
        // "AG001" ne doit jamais rapprocher un site de code "001" (donc "1").
        $numeric = ReferenceValueResolver::normalizeCodeKey('001');
        $this->assertNotSame($numeric, ReferenceValueResolver::normalizeCodeKey('AG001'));
        $this->assertNotSame($numeric, ReferenceValueResolver::normalizeCodeKey('DEP01'));
        $this->assertNotSame($numeric, ReferenceValueResolver::normalizeCodeKey('SITE-001'));
    }

    public function test_normalize_code_key_is_stable_across_php_scalar_types_from_excel(): void
    {
        // Excel/PhpSpreadsheet peut transmettre "001" comme chaîne, entier ou
        // flottant selon le format de cellule ; le résultat métier doit être
        // identique quelle que soit la représentation reçue une fois castée
        // en chaîne (ce que fait systématiquement l'appelant, cf.
        // SiteImportParser — jamais de cast numérique direct).
        $viaString = ReferenceValueResolver::normalizeCodeKey('001');
        $this->assertSame($viaString, ReferenceValueResolver::normalizeCodeKey((string) 1));
        $this->assertSame($viaString, ReferenceValueResolver::normalizeCodeKey((string) 1.0));
    }
}
