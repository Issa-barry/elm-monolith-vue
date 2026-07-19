<?php

namespace Tests\Feature;

use App\Enums\StatutCommission;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Services\CommandeVenteService;
use App\Services\CommissionVentePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Annuler une commande alors que sa commission a déjà été générée (dès `A_CHARGER`)
 * ne doit pas laisser une commission "orpheline" impayée indéfiniment — voir
 * CommandeVenteService::annulerCommissionsAssociees().
 */
class CommandeVenteAnnulationCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_annuler_commande_annule_les_parts_de_commission_non_payees(): void
    {
        $org = Organization::factory()->create();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
        $client = Client::create([
            'organization_id' => $org->id,
            'nom' => 'Client Test',
            'prenom' => 'Test',
            'is_active' => true,
            'cashback_eligible' => false,
        ]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commande = CommandeVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'reference' => 'CMD-'.uniqid(),
            'statut' => 'a_charger',
            'total_commande' => 500000,
        ]);

        $commission = CommissionVente::create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => null,
            'montant_commande' => 500000,
            'montant_commission_totale' => 100000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $part = $commission->parts()->create([
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'taux_commission' => 100,
            'montant_brut' => 100000,
            'frais_supplementaires' => 0,
            'montant_net' => 100000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommandeVenteService::annuler($commande, 'Erreur de saisie');

        $this->assertSame(StatutCommission::ANNULEE, $part->fresh()->statut);
        $this->assertSame(StatutCommission::ANNULEE, $commission->fresh()->statut);

        // Une part annulée ne doit plus jamais réapparaître comme disponible au paiement.
        $disponibles = CommissionVentePaiementService::partsDisponibles($org->id, 'livreur', $livreur->id);
        $this->assertCount(0, $disponibles);
    }

    public function test_annuler_commande_preserve_les_parts_deja_payees(): void
    {
        $org = Organization::factory()->create();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
        $client = Client::create([
            'organization_id' => $org->id,
            'nom' => 'Client Test',
            'prenom' => 'Test',
            'is_active' => true,
            'cashback_eligible' => false,
        ]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commande = CommandeVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'reference' => 'CMD-'.uniqid(),
            'statut' => 'a_charger',
            'total_commande' => 500000,
        ]);

        $commission = CommissionVente::create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => null,
            'montant_commande' => 500000,
            'montant_commission_totale' => 100000,
            'montant_verse' => 100000,
            'statut' => StatutCommission::PAYE->value,
        ]);

        $part = $commission->parts()->create([
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'taux_commission' => 100,
            'montant_brut' => 100000,
            'frais_supplementaires' => 0,
            'montant_net' => 100000,
            'montant_verse' => 100000,
            'statut' => StatutCommission::PAYE->value,
        ]);

        CommandeVenteService::annuler($commande, 'Erreur de saisie');

        // Une part déjà soldée garde son historique de paiement — jamais rétroactivement annulée.
        $this->assertSame(StatutCommission::PAYE, $part->fresh()->statut);
        $this->assertSame(StatutCommission::PAYE, $commission->fresh()->statut);
    }
}
