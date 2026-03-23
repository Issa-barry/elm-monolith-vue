<?php

namespace Tests\Unit;

use App\Enums\StatutCommission;
use App\Models\CommissionVente;
use App\Models\VersementCommission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionVenteTest extends TestCase
{
    use RefreshDatabase;

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function test_montant_restant_livreur_est_calcule_correctement(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_part_livreur'  => 3000,
            'montant_verse_livreur' => 1000,
        ]);

        $this->assertEquals(2000.0, $commission->montant_restant_livreur);
    }

    public function test_montant_restant_proprietaire_est_calcule_correctement(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_part_proprietaire'  => 2000,
            'montant_verse_proprietaire' => 2000,
        ]);

        $this->assertEquals(0.0, $commission->montant_restant_proprietaire);
    }

    public function test_montant_restant_ne_peut_pas_etre_negatif(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_part_livreur'  => 1000,
            'montant_verse_livreur' => 1500, // plus que la part
        ]);

        $this->assertEquals(0.0, $commission->montant_restant_livreur);
    }

    public function test_montant_restant_total_est_somme_des_deux_parts(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_commission'         => 5000,
            'montant_verse'              => 1000,
        ]);

        $this->assertEquals(4000.0, $commission->montant_restant);
    }

    // ── recalculStatut ────────────────────────────────────────────────────────

    public function test_statut_passe_en_attente_si_aucun_versement(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_commission' => 5000,
            'montant_verse'      => 0,
            'statut'             => StatutCommission::EN_ATTENTE,
        ]);

        $commission->recalculStatut();

        $this->assertEquals(StatutCommission::EN_ATTENTE, $commission->fresh()->statut);
    }

    public function test_statut_passe_partielle_si_versement_partiel(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_commission'        => 5000,
            'montant_part_livreur'      => 3000,
            'montant_part_proprietaire' => 2000,
        ]);

        $commission->versements()->create([
            'montant'        => 1000,
            'beneficiaire'   => 'livreur',
            'date_versement' => now()->toDateString(),
            'mode_paiement'  => 'especes',
        ]);

        $commission->recalculStatut();
        $fresh = $commission->fresh();

        $this->assertEquals(StatutCommission::PARTIELLE, $fresh->statut);
        $this->assertEquals(1000.0, (float) $fresh->montant_verse_livreur);
        $this->assertEquals(0.0, (float) $fresh->montant_verse_proprietaire);
        $this->assertEquals(1000.0, (float) $fresh->montant_verse);
    }

    public function test_statut_passe_versee_quand_tout_est_verse(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_commission'        => 5000,
            'montant_part_livreur'      => 3000,
            'montant_part_proprietaire' => 2000,
        ]);

        $commission->versements()->createMany([
            ['montant' => 3000, 'beneficiaire' => 'livreur',      'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes'],
            ['montant' => 2000, 'beneficiaire' => 'proprietaire', 'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes'],
        ]);

        $commission->recalculStatut();
        $fresh = $commission->fresh();

        $this->assertEquals(StatutCommission::VERSEE, $fresh->statut);
        $this->assertEquals(3000.0, (float) $fresh->montant_verse_livreur);
        $this->assertEquals(2000.0, (float) $fresh->montant_verse_proprietaire);
        $this->assertEquals(5000.0, (float) $fresh->montant_verse);
    }

    public function test_recalcul_ignore_commission_annulee(): void
    {
        $commission = CommissionVente::factory()->create([
            'statut' => StatutCommission::ANNULEE,
        ]);

        $result = $commission->recalculStatut();

        $this->assertFalse($result);
        $this->assertEquals(StatutCommission::ANNULEE, $commission->fresh()->statut);
    }

    public function test_versements_par_beneficiaire_sont_comptes_separement(): void
    {
        $commission = CommissionVente::factory()->create([
            'montant_commission'        => 5000,
            'montant_part_livreur'      => 3000,
            'montant_part_proprietaire' => 2000,
        ]);

        $commission->versements()->createMany([
            ['montant' => 1500, 'beneficiaire' => 'livreur',      'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes'],
            ['montant' => 1500, 'beneficiaire' => 'livreur',      'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes'],
            ['montant' => 800,  'beneficiaire' => 'proprietaire', 'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes'],
        ]);

        $commission->recalculStatut();
        $fresh = $commission->fresh();

        $this->assertEquals(3000.0, (float) $fresh->montant_verse_livreur);
        $this->assertEquals(800.0,  (float) $fresh->montant_verse_proprietaire);
        $this->assertEquals(3800.0, (float) $fresh->montant_verse);
        $this->assertEquals(StatutCommission::PARTIELLE, $fresh->statut);
    }

    // ── isVersee / isAnnulee ──────────────────────────────────────────────────

    public function test_is_versee_retourne_true_quand_statut_versee(): void
    {
        $commission = CommissionVente::factory()->create(['statut' => StatutCommission::VERSEE]);
        $this->assertTrue($commission->isVersee());
    }

    public function test_is_annulee_retourne_true_quand_statut_annulee(): void
    {
        $commission = CommissionVente::factory()->create(['statut' => StatutCommission::ANNULEE]);
        $this->assertTrue($commission->isAnnulee());
    }
}
