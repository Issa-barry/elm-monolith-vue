<?php

namespace Tests\Unit;

use App\Services\Commission\CommissionPartageLivraisonValidator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests unitaires du validateur de partage Livreur (montants GNF entiers
 * fixes) — purement en mémoire, aucune base de données requise. Égalité
 * entière stricte, sans aucune tolérance : remplace CommissionRepartitionEngine
 * pour la seule cible équipe_livraison (cf. incident CMD-230826-004, où une
 * tolérance flottante ±0.01 sur un partage en % avait laissé passer une
 * config invalide à la saisie puis l'avait rejetée à la génération).
 */
class CommissionPartageLivraisonValidatorTest extends TestCase
{
    private function membre(string $beneficiaireId, mixed $montant): object
    {
        return (object) [
            'beneficiaire_id' => $beneficiaireId,
            'montant_unitaire' => $montant,
        ];
    }

    /** @test */
    public function accepte_une_somme_exactement_egale_a_lenveloppe(): void
    {
        $membres = new Collection([
            $this->membre('a', 100),
            $this->membre('b', 150),
            $this->membre('c', 700),
        ]);

        CommissionPartageLivraisonValidator::valider($membres, 950);

        $this->addToAssertionCount(1); // Aucune exception levée.
    }

    /** @test */
    public function rejette_une_somme_inferieure_avec_le_reste_exact(): void
    {
        $membres = new Collection([
            $this->membre('a', 100),
            $this->membre('b', 150),
        ]);

        try {
            CommissionPartageLivraisonValidator::valider($membres, 950);
            $this->fail('InvalidArgumentException attendue.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('700', $e->getMessage());
        }
    }

    /** @test */
    public function rejette_une_somme_superieure_avec_le_depassement_exact(): void
    {
        $membres = new Collection([
            $this->membre('a', 600),
            $this->membre('b', 500),
        ]);

        try {
            CommissionPartageLivraisonValidator::valider($membres, 950);
            $this->fail('InvalidArgumentException attendue.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('150', $e->getMessage());
        }
    }

    /** @test */
    public function rejette_un_montant_decimal(): void
    {
        $membres = new Collection([
            $this->membre('a', 100.5),
            $this->membre('b', 849.5),
        ]);

        $this->expectException(InvalidArgumentException::class);
        CommissionPartageLivraisonValidator::valider($membres, 950);
    }

    /** @test */
    public function rejette_un_montant_negatif(): void
    {
        $membres = new Collection([
            $this->membre('a', -50),
            $this->membre('b', 1000),
        ]);

        $this->expectException(InvalidArgumentException::class);
        CommissionPartageLivraisonValidator::valider($membres, 950);
    }

    /** @test */
    public function rejette_un_membre_sans_montant(): void
    {
        $membres = new Collection([
            $this->membre('a', null),
            $this->membre('b', 950),
        ]);

        $this->expectException(InvalidArgumentException::class);
        CommissionPartageLivraisonValidator::valider($membres, 950);
    }

    /** @test */
    public function rejette_un_beneficiaire_en_double(): void
    {
        $membres = new Collection([
            $this->membre('a', 500),
            $this->membre('a', 450),
        ]);

        $this->expectException(InvalidArgumentException::class);
        CommissionPartageLivraisonValidator::valider($membres, 950);
    }

    /** @test */
    public function rejette_une_enveloppe_positive_sans_aucun_membre(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommissionPartageLivraisonValidator::valider(new Collection, 950);
    }

    /** @test */
    public function accepte_une_enveloppe_a_zero_sans_partage(): void
    {
        CommissionPartageLivraisonValidator::valider(new Collection, 0);

        $this->addToAssertionCount(1); // Aucune exception levée.
    }

    /** @test */
    public function accepte_cinq_membres_totalisant_exactement_lenveloppe_950(): void
    {
        // Cas exact de l'incident CMD-230826-004 : 100 + 100 + 150 + 150 + 450 = 950.
        $membres = new Collection([
            $this->membre('chauffeur-1', 100),
            $this->membre('chauffeur-2', 100),
            $this->membre('convoyeur-1', 150),
            $this->membre('convoyeur-2', 150),
            $this->membre('convoyeur-3', 450),
        ]);

        CommissionPartageLivraisonValidator::valider($membres, 950);

        $this->addToAssertionCount(1); // Aucune exception levée.
    }

    // ── Membres requis (décision du 24/09/2026) ─────────────────────────────

    /** @test */
    public function rejette_un_membre_requis_sans_ligne_meme_si_la_somme_est_exacte(): void
    {
        $membres = new Collection([$this->membre('a', 800)]);

        try {
            CommissionPartageLivraisonValidator::valider($membres, 800, ['a', 'b']);
            $this->fail('InvalidArgumentException attendue.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sans part : b', $e->getMessage());
        }
    }

    /** @test */
    public function accepte_une_ligne_a_zero_pour_un_membre_requis(): void
    {
        $membres = new Collection([
            $this->membre('a', 800),
            $this->membre('b', 0),
        ]);

        CommissionPartageLivraisonValidator::valider($membres, 800, ['a', 'b']);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function nexige_aucun_membre_quand_le_bareme_vaut_zero(): void
    {
        CommissionPartageLivraisonValidator::valider(new Collection, 0, ['a', 'b']);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function sans_membres_requis_le_comportement_de_generation_est_inchange(): void
    {
        // La génération n'exige pas la présence de chaque membre (aucun effet sur les montants).
        CommissionPartageLivraisonValidator::valider(new Collection([$this->membre('a', 800)]), 800);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function rejette_une_equipe_sans_membre_actif_meme_si_danciennes_lignes_totalisent_le_bareme(): void
    {
        try {
            CommissionPartageLivraisonValidator::valider(new Collection([$this->membre('ancien', 800)]), 800, []);
            $this->fail('InvalidArgumentException attendue.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Aucun membre actif', $e->getMessage());
        }
    }
}
