<?php

namespace Tests\Unit;

use App\Enums\StatutFactureVente;
use App\Models\FactureVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactureVenteTest extends TestCase
{
    use RefreshDatabase;

    // ── recalculStatut ────────────────────────────────────────────────────────

    public function test_statut_passe_impayee_si_rien_encaisse(): void
    {
        $facture = FactureVente::factory()->create(['montant_net' => 5000]);

        $facture->recalculStatut();

        $this->assertEquals(StatutFactureVente::IMPAYEE, $facture->fresh()->statut_facture);
    }

    public function test_statut_passe_partiel_si_encaissement_partiel(): void
    {
        $facture = FactureVente::factory()->create(['montant_net' => 5000]);
        $facture->encaissements()->create([
            'montant' => 2000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $facture->recalculStatut();

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);
    }

    public function test_statut_passe_payee_si_encaissement_complet(): void
    {
        $facture = FactureVente::factory()->create(['montant_net' => 5000]);
        $facture->encaissements()->create([
            'montant' => 5000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $facture->recalculStatut();

        $this->assertEquals(StatutFactureVente::PAYEE, $facture->fresh()->statut_facture);
    }

    public function test_recalcul_ignore_facture_annulee(): void
    {
        $facture = FactureVente::factory()->create([
            'montant_net' => 5000,
            'statut_facture' => StatutFactureVente::ANNULEE,
        ]);

        $result = $facture->recalculStatut();

        $this->assertFalse($result);
    }

    // La génération de commission (équipe, chauffeur/convoyeur, idempotence,
    // véhicule sans équipe) est couverte de bout en bout, sur le moteur réel
    // (CommissionEnveloppeGenerator), par CommissionTriggerVenteTest et
    // CommandeVenteCommissionEligibiliteTest — jamais dupliquée ici.
}
