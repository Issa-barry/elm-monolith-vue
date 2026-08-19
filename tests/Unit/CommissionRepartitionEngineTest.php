<?php

namespace Tests\Unit;

use App\Services\Commission\CommissionRepartitionEngine;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests unitaires du moteur générique de répartition — purement en mémoire,
 * aucune base de données requise. Le moteur doit être identique quel que
 * soit le type de groupe appelant (équipe livraison, équipe dépôt...).
 */
class CommissionRepartitionEngineTest extends TestCase
{
    private function membre(string $id, string $beneficiaireId, float $part): object
    {
        return (object) [
            'id' => $id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $beneficiaireId,
            'part_pourcentage' => $part,
        ];
    }

    /** @test */
    public function repartit_deux_membres_sans_reliquat(): void
    {
        $membres = new Collection([
            $this->membre('a', 'chauffeur', 60),
            $this->membre('b', 'convoyeur', 40),
        ]);

        $parts = CommissionRepartitionEngine::repartir(300.0, $membres);

        $this->assertCount(2, $parts);
        $this->assertEqualsWithDelta(180.0, $parts[0]['montant'], 0.001);
        $this->assertEqualsWithDelta(120.0, $parts[1]['montant'], 0.001);
        $this->assertEqualsWithDelta(300.0, array_sum(array_column($parts, 'montant')), 0.001);
    }

    /** @test */
    public function membre_unique_recoit_100_pourcent_et_le_montant_total(): void
    {
        $membres = new Collection([$this->membre('a', 'seul', 100)]);

        $parts = CommissionRepartitionEngine::repartir(950.0, $membres);

        $this->assertCount(1, $parts);
        $this->assertSame(950.0, $parts[0]['montant']);
        $this->assertSame(100.0, $parts[0]['part_pourcentage']);
    }

    /** @test */
    public function le_reliquat_darrondi_est_assigne_au_dernier_membre_par_ordre_stable(): void
    {
        // 100 / 3 = 33.33... × 3 = 99.99, jamais 100 exactement en arrondi 2 décimales.
        $membres = new Collection([
            $this->membre('a', 'x', 33.34),
            $this->membre('b', 'y', 33.33),
            $this->membre('c', 'z', 33.33),
        ]);

        $parts = CommissionRepartitionEngine::repartir(100.0, $membres);

        $somme = array_sum(array_column($parts, 'montant'));
        $this->assertEqualsWithDelta(100.0, $somme, 0.0001, 'La somme des parts doit toujours égaler le montant total, jamais un écart silencieux.');
    }

    /** @test */
    public function rejette_une_somme_differente_de_100_pourcent(): void
    {
        $membres = new Collection([
            $this->membre('a', 'x', 60),
            $this->membre('b', 'y', 30),
        ]);

        $this->expectException(InvalidArgumentException::class);
        CommissionRepartitionEngine::repartir(300.0, $membres);
    }

    /** @test */
    public function rejette_un_groupe_sans_membre(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommissionRepartitionEngine::repartir(300.0, new Collection);
    }

    /** @test */
    public function rejette_une_part_individuelle_hors_bornes(): void
    {
        $membres = new Collection([
            $this->membre('a', 'x', 150),
            $this->membre('b', 'y', -50),
        ]);

        $this->expectException(InvalidArgumentException::class);
        CommissionRepartitionEngine::repartir(300.0, $membres);
    }

    /** @test */
    public function trois_membres_repartition_egale_totalise_exactement_lenveloppe(): void
    {
        $membres = new Collection([
            $this->membre('a', 'x', 33.33),
            $this->membre('b', 'y', 33.33),
            $this->membre('c', 'z', 33.34),
        ]);

        $parts = CommissionRepartitionEngine::repartir(30000.0, $membres);

        $this->assertEqualsWithDelta(30000.0, array_sum(array_column($parts, 'montant')), 0.0001);
    }
}
