<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\StatutCommission;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Services\CommandeVenteService;
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

        $commission = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->makeProcessus($org)->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) \Illuminate\Support\Str::ulid(),
            'montant_total' => 100000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $part = $commission->parts()->create([
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => 100000,
            'montant_net' => 100000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommandeVenteService::annuler($commande, 'Erreur de saisie');

        $this->assertSame(StatutCommission::ANNULEE, $part->fresh()->statut);
        $this->assertSame(StatutCommission::ANNULEE, $commission->fresh()->statut);
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

        $commission = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->makeProcessus($org)->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) \Illuminate\Support\Str::ulid(),
            'montant_total' => 100000,
            'earned_at' => now(),
            'statut' => StatutCommission::PAYE->value,
        ]);

        $part = $commission->parts()->create([
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => 100000,
            'montant_net' => 100000,
            'montant_verse' => 100000,
            'statut' => StatutCommission::PAYE->value,
        ]);

        CommandeVenteService::annuler($commande, 'Erreur de saisie');

        // Une part déjà soldée garde son historique de paiement — jamais rétroactivement annulée.
        $this->assertSame(StatutCommission::PAYE, $part->fresh()->statut);
        $this->assertSame(StatutCommission::PAYE, $commission->fresh()->statut);
    }

    private function makeProcessus(Organization $org): CommissionProcessus
    {
        return CommissionProcessus::firstOrCreate(
            ['organization_id' => $org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => 'operation',
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );
    }
}
