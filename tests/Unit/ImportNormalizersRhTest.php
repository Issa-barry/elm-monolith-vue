<?php

namespace Tests\Unit;

use App\Services\ImportFlotte\Normalizers\ImportDateNormalizer;
use App\Services\ImportFlotte\Normalizers\ImportMontantNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Normaliseurs de l'import RH : dates et montants de cellule Excel. */
class ImportNormalizersRhTest extends TestCase
{
    public static function datesValides(): array
    {
        return [
            'JJ/MM/AAAA' => ['01/03/2024', '2024-03-01'],
            'JJ-MM-AAAA' => ['01-03-2024', '2024-03-01'],
            'AAAA-MM-JJ' => ['2024-03-01', '2024-03-01'],
            'numéro de série Excel' => [45352, '2024-03-01'],
            'numéro de série en texte' => ['45352', '2024-03-01'],
            'objet date' => [new \DateTime('2024-03-01 15:00'), '2024-03-01'],
        ];
    }

    #[DataProvider('datesValides')]
    public function test_lit_les_formats_de_date_acceptes(mixed $valeur, string $attendu): void
    {
        $this->assertSame([$attendu, null], ImportDateNormalizer::toDate($valeur));
    }

    public function test_une_cellule_vide_n_est_pas_une_erreur(): void
    {
        $this->assertSame([null, null], ImportDateNormalizer::toDate(''));
        $this->assertSame([null, null], ImportDateNormalizer::toDate(null));
    }

    public function test_une_date_impossible_est_une_erreur_jamais_devinee(): void
    {
        foreach (['31/02/2024', '2024/13/01', 'demain', '1er mars'] as $valeur) {
            [$date, $erreur] = ImportDateNormalizer::toDate($valeur);
            $this->assertNull($date, $valeur);
            $this->assertNotNull($erreur, $valeur);
        }
    }

    public static function montantsValides(): array
    {
        return [
            'nombre' => [2500000, 2500000.0],
            'décimal' => [2500000.5, 2500000.5],
            'espaces' => ['2 500 000', 2500000.0],
            'espaces insécables' => ["2\u{202F}500\u{202F}000", 2500000.0],
            'points' => ['2.500.000', 2500000.0],
            'avec GNF' => ['2 500 000 GNF', 2500000.0],
            'zéro' => ['0', 0.0],
        ];
    }

    #[DataProvider('montantsValides')]
    public function test_lit_les_montants(mixed $valeur, float $attendu): void
    {
        $this->assertSame([$attendu, null], ImportMontantNormalizer::toMontant($valeur));
    }

    public function test_un_montant_invalide_est_une_erreur_qui_ne_cite_pas_la_valeur(): void
    {
        foreach (['-1000', 'beaucoup', -5] as $valeur) {
            [$montant, $erreur] = ImportMontantNormalizer::toMontant($valeur);
            $this->assertNull($montant);
            $this->assertNotNull($erreur);
            $this->assertStringNotContainsString((string) abs((int) $valeur ?: 0) ?: 'beaucoup', $erreur);
        }
    }
}
