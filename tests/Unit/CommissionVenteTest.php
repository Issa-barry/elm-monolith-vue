<?php

namespace Tests\Unit;

use App\Enums\StatutCommission;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionVenteTest extends TestCase
{
    use RefreshDatabase;

    private function makeCommission(array $overrides = []): CommissionVente
    {
        return CommissionVente::factory()->create($overrides);
    }

    private function makePart(CommissionVente $commission, array $overrides = []): CommissionPart
    {
        return $commission->parts()->create(array_merge([
            'type_beneficiaire' => 'livreur',
            'beneficiaire_nom' => 'Test Livreur',
            'taux_commission' => 100,
            'montant_brut' => 5000,
            'frais_supplementaires' => 0,
            'montant_net' => 5000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ], $overrides));
    }

    // ── Accessor: montant_restant (CommissionVente) ───────────────────────────

    public function test_montant_restant_commission_est_somme_des_parts(): void
    {
        $commission = $this->makeCommission();

        $this->makePart($commission, [
            'type_beneficiaire' => 'livreur',
            'taux_commission' => 60,
            'montant_brut' => 3000,
            'montant_net' => 3000,
            'montant_verse' => 1000,
            'statut' => StatutCommission::PARTIEL,
        ]);
        $this->makePart($commission, [
            'type_beneficiaire' => 'proprietaire',
            'beneficiaire_nom' => 'Test Proprio',
            'taux_commission' => 40,
            'montant_brut' => 2000,
            'montant_net' => 2000,
            'montant_verse' => 2000,
            'statut' => StatutCommission::PAYE,
        ]);

        // Livreur restant: 3000-1000=2000, proprietaire restant: 0 → total 2000
        $this->assertEquals(2000.0, $commission->montant_restant);
    }

    // ── Accessor: montant_restant (CommissionPart) ────────────────────────────

    public function test_montant_restant_part_ne_peut_pas_etre_negatif(): void
    {
        $commission = $this->makeCommission();
        $part = $this->makePart($commission, [
            'montant_net' => 1000,
            'montant_verse' => 1500, // sur-versé
        ]);

        $this->assertEquals(0.0, $part->montant_restant);
    }

    public function test_montant_restant_part_calcule_correctement(): void
    {
        $commission = $this->makeCommission();
        $part = $this->makePart($commission, [
            'montant_net' => 3000,
            'montant_verse' => 1000,
        ]);

        $this->assertEquals(2000.0, $part->montant_restant);
    }

    // ── recalculStatutGlobal ──────────────────────────────────────────────────

    public function test_statut_global_passe_impaye_si_aucun_versement(): void
    {
        $commission = $this->makeCommission(['statut' => StatutCommission::IMPAYE]);
        $this->makePart($commission, ['montant_verse' => 0]);

        $commission->recalculStatutGlobal();

        $this->assertEquals(StatutCommission::IMPAYE, $commission->fresh()->statut);
    }

    public function test_statut_global_passe_partiel_si_versement_partiel(): void
    {
        $commission = $this->makeCommission();
        $part = $this->makePart($commission, ['montant_net' => 5000, 'montant_verse' => 0]);

        $part->versements()->create([
            'montant' => 2000,
            'date_versement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $part->recalculStatut();

        $this->assertEquals(StatutCommission::PARTIEL, $commission->fresh()->statut);
        $this->assertEquals(2000.0, (float) $commission->fresh()->montant_verse);
    }

    public function test_statut_global_passe_paye_quand_tout_est_verse(): void
    {
        $commission = $this->makeCommission();
        $part = $this->makePart($commission, ['montant_net' => 5000, 'montant_verse' => 0]);

        $part->versements()->create([
            'montant' => 5000,
            'date_versement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $part->recalculStatut();

        $this->assertEquals(StatutCommission::PAYE, $commission->fresh()->statut);
        $this->assertEquals(5000.0, (float) $commission->fresh()->montant_verse);
    }

    public function test_statut_global_avec_deux_parts_partiellement_versees(): void
    {
        $commission = $this->makeCommission();

        $partLivreur = $this->makePart($commission, [
            'type_beneficiaire' => 'livreur',
            'taux_commission' => 60,
            'montant_net' => 3000,
            'montant_verse' => 0,
        ]);
        $partProp = $this->makePart($commission, [
            'type_beneficiaire' => 'proprietaire',
            'beneficiaire_nom' => 'Test Proprio',
            'taux_commission' => 40,
            'montant_net' => 2000,
            'montant_verse' => 0,
        ]);

        $partLivreur->versements()->create(['montant' => 1500, 'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes']);
        $partProp->versements()->create(['montant' => 800, 'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes']);

        $partLivreur->recalculStatut();
        $partProp->recalculStatut();

        $fresh = $commission->fresh();
        $this->assertEquals(StatutCommission::PARTIEL, $fresh->statut);
        $this->assertEquals(2300.0, (float) $fresh->montant_verse);
    }

    public function test_statut_global_recalcul_complet_passe_a_paye(): void
    {
        $commission = $this->makeCommission();
        $part = $this->makePart($commission, ['montant_net' => 3000, 'montant_verse' => 3000, 'statut' => StatutCommission::PAYE]);

        $commission->recalculStatutGlobal();

        $this->assertEquals(StatutCommission::PAYE, $commission->fresh()->statut);
    }

    // ── isPaye / isVersee ─────────────────────────────────────────────────────

    public function test_is_paye_retourne_true_quand_statut_paye(): void
    {
        $commission = $this->makeCommission(['statut' => StatutCommission::PAYE]);
        $this->assertTrue($commission->isPaye());
    }

    public function test_is_paye_retourne_false_quand_statut_impaye(): void
    {
        $commission = $this->makeCommission(['statut' => StatutCommission::IMPAYE]);
        $this->assertFalse($commission->isPaye());
    }

    public function test_statut_ne_contient_pas_annule(): void
    {
        $values = array_column(StatutCommission::cases(), 'value');
        $this->assertNotContains('annule', $values);
        $this->assertNotContains('annulee', $values);
        $this->assertNotContains('cancelled', $values);
    }
}
