<?php

namespace Tests\Feature\Api\Client;

use App\Enums\CommissionActivationStatut;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Régression du 27/08/2026 (fiabilisation OpenAPI, point 2) : GainsController,
 * VehiculeCommissionsController et LivraisonsEnCoursController sont passés
 * d'arrays construits à la main à des DTOs typés (VehiculeEarningsRow-like)
 * pour que Scramble arrête d'inférer `string[]` — ces contrôleurs n'avaient
 * aucun test avant ce chantier ; celui-ci prouve que le JSON produit reste
 * identique après le refactor, pas seulement que le schéma OpenAPI est correct.
 */
class LegacyEndpointsSchemaRegressionTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    public function test_vehicule_commissions_endpoint_still_returns_the_expected_json_shape(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $user->proprietaire->id,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'reference' => 'CMD-TEST-001',
        ]);

        $processus = CommissionProcessus::create([
            'organization_id' => $org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 10000,
            'earned_at' => now(),
            'statut' => StatutCommission::PARTIEL->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $user->proprietaire->id,
            'montant_brut' => 10000,
            'montant_net' => 10000,
            'montant_verse' => 4000,
            'statut' => StatutCommission::PARTIEL->value,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.vehicules.commissions', $vehicule->id))->assertOk();

        $response->assertJsonCount(1);
        $response->assertJsonPath('0.reference', 'CMD-TEST-001');
        $response->assertJsonPath('0.montant_net', 10000);
        $response->assertJsonPath('0.montant_verse', 4000);
        $response->assertJsonPath('0.montant_restant', 6000);
        $response->assertJsonPath('0.statut', 'partiel');
        $response->assertJsonStructure([0 => ['id', 'reference', 'date', 'montant_net', 'montant_a_payer', 'montant_verse', 'montant_restant', 'statut', 'mois']]);
    }

    /**
     * Régression d'un vrai bug découvert en écrivant ce test (aucun test ne
     * couvrait ce contrôleur avant ce chantier) : `$row` renvoyé par
     * `CommissionEnveloppePart::query()->...->get()` est un modèle Eloquent
     * hydraté (pas un stdClass) — `statut` y est casté en enum, jamais une
     * string brute. Le `match()` comparait cet enum à des littéraux `->value`
     * (des strings) : `===` ne matchait jamais, donc l'API affichait TOUJOURS
     * "en_attente" quel que soit le vrai statut de paiement.
     */
    public function test_vehicule_commissions_endpoint_reports_the_real_payment_statut(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $user->proprietaire->id,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
        ]);

        $processus = CommissionProcessus::create([
            'organization_id' => $org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 5000,
            'earned_at' => now(),
            'statut' => StatutCommission::PAYE->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $user->proprietaire->id,
            'montant_brut' => 5000,
            'montant_net' => 5000,
            'montant_verse' => 5000,
            'statut' => StatutCommission::PAYE->value,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.vehicules.commissions', $vehicule->id))
            ->assertOk()
            ->assertJsonPath('0.statut', 'paye');
    }

    public function test_livraisons_en_cours_endpoint_still_returns_the_expected_json_shape(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $user->proprietaire->id,
            'nom_vehicule' => 'ABARRY',
        ]);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Source',
            'type' => 'depot',
            'localisation' => 'Test',
        ]);
        $creator = User::factory()->create(['organization_id' => $org->id]);

        TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-TEST-001',
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutTransfert::TRANSIT->value,
            'created_by' => $creator->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.livraisons.en-cours'))->assertOk();

        $response->assertJsonCount(1);
        $response->assertJsonPath('0.reference', 'TRF-TEST-001');
        $response->assertJsonPath('0.statut', 'transit');
        $response->assertJsonPath('0.vehicule.nom', 'ABARRY');
        $response->assertJsonStructure([0 => [
            'id', 'reference', 'statut', 'statut_label', 'site_source', 'site_destination',
            'vehicule' => ['nom', 'immatriculation'], 'equipe_nom', 'date_depart', 'date_arrivee_prevue', 'nb_packs',
        ]]);
    }

    public function test_gains_mine_endpoint_still_returns_the_expected_json_shape(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $user->proprietaire->id,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
        ]);

        $processus = CommissionProcessus::create([
            'organization_id' => $org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 8000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $user->proprietaire->id,
            'montant_brut' => 8000,
            'montant_net' => 8000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.gains.mine'))->assertOk();

        $response->assertJsonPath('total_brut', 8000);
        $response->assertJsonCount(1, 'par_vehicule');
        $response->assertJsonPath('par_vehicule.0.vehicule_id', $vehicule->id);
        $response->assertJsonPath('par_vehicule.0.nb_commandes', 1);
        $response->assertJsonStructure(['par_vehicule' => [0 => [
            'vehicule_id', 'nom', 'immatriculation', 'total_brut', 'total_net',
            'total_a_payer', 'total_verse', 'total_restant', 'nb_commandes',
        ]]]);
    }
}
