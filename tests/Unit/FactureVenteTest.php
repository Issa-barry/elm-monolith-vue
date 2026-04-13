<?php

namespace Tests\Unit;

use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\CommissionGenerator;
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

    private function makeProduit(Organization $org): Produit
    {
        return Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit Test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 0,
            'qte_stock' => 0,
        ]);
    }

    // ── CommissionGenerator (nouveau modèle) ──────────────────────────────────

    public function test_commission_generee_quand_vehicule_a_equipe_et_taux_valide(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $produit = $this->makeProduit($org);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 40,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'taux_commission' => 60,
            'role' => 'principal',
            'ordre' => 0,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 10000,
        ]);
        $commande->lignes()->create([
            'produit_id' => $produit->id,
            'qte' => 1,
            'prix_vente_snapshot' => 10000,
            'prix_usine_snapshot' => 0,
            'total_ligne' => 10000,
        ]);

        CommissionGenerator::generateForCommandeIfMissing($commande);

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->first();
        $this->assertNotNull($commission, 'La commission doit être créée');
        $this->assertEquals(10000.0, (float) $commission->montant_commission_totale);

        $partLivreur = $commission->parts()->where('type_beneficiaire', 'livreur')->first();
        $partProp = $commission->parts()->where('type_beneficiaire', 'proprietaire')->first();
        $this->assertEquals(6000.0, (float) $partLivreur->montant_brut);
        $this->assertEquals(4000.0, (float) $partProp->montant_brut);
        $this->assertEquals($livreur->id, $partLivreur->livreur_id);
        $this->assertEquals($proprietaire->id, $partProp->proprietaire_id);
    }

    public function test_commission_non_generee_si_vehicule_sans_equipe(): void
    {
        $org = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 10000,
        ]);

        CommissionGenerator::generateForCommandeIfMissing($commande);

        $this->assertEquals(0, CommissionVente::where('commande_vente_id', $commande->id)->count());
    }

    public function test_commission_non_dupliquee_si_commande_deja_traitee(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $produit = $this->makeProduit($org);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 40,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'taux_commission' => 60,
            'role' => 'principal',
            'ordre' => 0,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 5000,
        ]);
        $commande->lignes()->create([
            'produit_id' => $produit->id,
            'qte' => 1,
            'prix_vente_snapshot' => 5000,
            'prix_usine_snapshot' => 0,
            'total_ligne' => 5000,
        ]);

        CommissionGenerator::generateForCommandeIfMissing($commande);
        CommissionGenerator::generateForCommandeIfMissing($commande);

        $this->assertEquals(1, CommissionVente::where('commande_vente_id', $commande->id)->count());
    }
}
