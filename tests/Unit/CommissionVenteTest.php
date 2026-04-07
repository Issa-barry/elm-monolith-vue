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
            'statut' => StatutCommission::EN_ATTENTE,
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
            'statut' => StatutCommission::PARTIELLE,
        ]);
        $this->makePart($commission, [
            'type_beneficiaire' => 'proprietaire',
            'beneficiaire_nom' => 'Test Proprio',
            'taux_commission' => 40,
            'montant_brut' => 2000,
            'montant_net' => 2000,
            'montant_verse' => 2000,
            'statut' => StatutCommission::VERSEE,
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

    public function test_statut_global_passe_en_attente_si_aucun_versement(): void
    {
        $commission = $this->makeCommission(['statut' => StatutCommission::EN_ATTENTE]);
        $this->makePart($commission, ['montant_verse' => 0]);

        $commission->recalculStatutGlobal();

        $this->assertEquals(StatutCommission::EN_ATTENTE, $commission->fresh()->statut);
    }

    public function test_statut_global_passe_partielle_si_versement_partiel(): void
    {
        $commission = $this->makeCommission();
        $part = $this->makePart($commission, ['montant_net' => 5000, 'montant_verse' => 0]);

        $part->versements()->create([
            'montant' => 2000,
            'date_versement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $part->recalculStatut();

        $this->assertEquals(StatutCommission::PARTIELLE, $commission->fresh()->statut);
        $this->assertEquals(2000.0, (float) $commission->fresh()->montant_verse);
    }

    public function test_statut_global_passe_versee_quand_tout_est_verse(): void
    {
        $commission = $this->makeCommission();
        $part = $this->makePart($commission, ['montant_net' => 5000, 'montant_verse' => 0]);

        $part->versements()->create([
            'montant' => 5000,
            'date_versement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $part->recalculStatut();

        $this->assertEquals(StatutCommission::VERSEE, $commission->fresh()->statut);
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
        $this->assertEquals(StatutCommission::PARTIELLE, $fresh->statut);
        $this->assertEquals(2300.0, (float) $fresh->montant_verse);
    }

    public function test_recalcul_ignore_commission_annulee(): void
    {
        $commission = $this->makeCommission(['statut' => StatutCommission::ANNULEE]);

        $result = $commission->recalculStatutGlobal();

        $this->assertFalse($result);
        $this->assertEquals(StatutCommission::ANNULEE, $commission->fresh()->statut);
    }

    // ── isVersee / isAnnulee ──────────────────────────────────────────────────

    public function test_is_versee_retourne_true_quand_statut_versee(): void
    {
        $commission = $this->makeCommission(['statut' => StatutCommission::VERSEE]);
        $this->assertTrue($commission->isVersee());
    }

    public function test_is_annulee_retourne_true_quand_statut_annulee(): void
    {
        $commission = $this->makeCommission(['statut' => StatutCommission::ANNULEE]);
        $this->assertTrue($commission->isAnnulee());
    }
}
