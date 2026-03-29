<?php

namespace Tests\Unit;

use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CommissionVente;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
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

    // ── genererCommission ─────────────────────────────────────────────────────

    public function test_commission_generee_quand_facture_devient_payee(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'livreur_principal_id' => $livreur->id,
            'taux_commission_livreur' => 60,
            'taux_commission_proprietaire' => 40,
        ]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 10000,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 10000,
        ]);

        // Encaissement complet
        $facture->encaissements()->create([
            'montant' => 10000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $facture->recalculStatut();

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->first();

        $this->assertNotNull($commission, 'La commission doit être créée');
        $this->assertEquals(6000.0, (float) $commission->montant_part_livreur);
        $this->assertEquals(4000.0, (float) $commission->montant_part_proprietaire);
        $this->assertEquals(10000.0, (float) $commission->montant_commission);
        $this->assertEquals($livreur->id, $commission->livreur_id);
    }

    public function test_commission_non_generee_si_taux_zero(): void
    {
        $org = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'taux_commission_livreur' => 0,
            'taux_commission_proprietaire' => 0,
        ]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 10000,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 10000,
        ]);

        $facture->encaissements()->create([
            'montant' => 10000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $facture->recalculStatut();

        $this->assertEquals(0, CommissionVente::where('commande_vente_id', $commande->id)->count());
    }

    public function test_commission_non_dupliquee_si_facture_deja_payee(): void
    {
        $org = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'taux_commission_livreur' => 60,
            'taux_commission_proprietaire' => 40,
        ]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 5000,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 5000,
        ]);

        // Premier encaissement → facture payée → commission créée
        $facture->encaissements()->create([
            'montant' => 5000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $facture->recalculStatut();

        // Deuxième recalcul → pas de doublon
        $facture->recalculStatut();

        $this->assertEquals(1, CommissionVente::where('commande_vente_id', $commande->id)->count());
    }
}
