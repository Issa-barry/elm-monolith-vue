<?php

namespace Tests\Feature\Api\Client;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class ActiviteControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function makeVehicule(Organization $org, string $proprietaireId, string $nom = 'Vehicule Test'): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireId,
            'nom_vehicule' => $nom,
        ]);
    }

    private function makeCommande(Organization $org, Vehicule $vehicule, array $overrides = []): CommandeVente
    {
        return CommandeVente::factory()->create(array_merge([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => now(),
        ], $overrides));
    }

    private function makeSite(Organization $org): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Test',
        ]);
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule, array $overrides = []): TransfertLogistique
    {
        $creator = User::factory()->create(['organization_id' => $org->id]);
        $site = $this->makeSite($org);

        return TransfertLogistique::create(array_merge([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutTransfert::CLOTURE->value,
            'date_depart_reelle' => now()->toDateString(),
            'created_by' => $creator->id,
        ], $overrides));
    }

    public function test_lists_both_ventes_and_transferts_regardless_of_statut(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::CLOTUREE->value]);
        $this->makeTransfert($org, $vehicule, ['statut' => StatutTransfert::CLOTURE->value]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine'))->assertOk();

        $response->assertJsonCount(2, 'data');
        $types = collect($response->json('data'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['logistique', 'vente'], $types);
    }

    public function test_isolates_another_proprietaires_activite(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->makeProprietaireUser($org);
        $ownerVehicule = $this->makeVehicule($org, $owner->proprietaire->id);
        $this->makeCommande($org, $ownerVehicule);

        $other = $this->makeProprietaireUser($org);
        $otherVehicule = $this->makeVehicule($org, $other->proprietaire->id);
        $this->makeCommande($org, $otherVehicule, ['reference' => 'CMD-OTHER']);

        Sanctum::actingAs($other, ['*']);

        $response = $this->getJson(route('client.activite.mine'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-OTHER', $response->json('data.0.reference'));
    }

    public function test_isolates_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($orgA);
        $vehiculeA = $this->makeVehicule($orgA, $userA->proprietaire->id);
        $this->makeCommande($orgA, $vehiculeA);

        $orgB = Organization::factory()->create();
        $userB = $this->makeProprietaireUser($orgB);

        Sanctum::actingAs($userB, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pure_proprietaire_without_equipe_sees_their_activity(): void
    {
        // Régression du manque documenté : /v1/mobile/livraisons-transferts (l'historique
        // existant) résout par équipe de livreur uniquement, inaccessible à un
        // proprietaire pur. Cet endpoint doit fonctionner sans aucune équipe.
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_livreur_sees_activity_of_their_teams_vehicule(): void
    {
        $org = Organization::factory()->create();
        $livreurUser = $this->makeLivreurUser($org);
        $proprietaireUser = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $proprietaireUser->proprietaire->id);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($livreurUser, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filters_by_type_vente(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule);
        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['type' => 'vente']))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('vente', $response->json('data.0.type'));
    }

    public function test_filters_by_type_logistique(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule);
        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['type' => 'logistique']))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('logistique', $response->json('data.0.type'));
    }

    public function test_statut_filter_requires_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine', ['statut' => 'cloturee']))
            ->assertStatus(422);
    }

    public function test_filters_by_statut_within_a_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::CLOTUREE->value, 'reference' => 'CMD-CLOTUREE']);
        $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::A_CHARGER->value, 'reference' => 'CMD-ACHARGER']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', [
            'type' => 'vente',
            'statut' => StatutCommandeVente::CLOTUREE->value,
        ]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-CLOTUREE', $response->json('data.0.reference'));
    }

    public function test_filters_by_vehicule_id(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeCommande($org, $vehiculeA, ['reference' => 'CMD-A']);
        $this->makeCommande($org, $vehiculeB, ['reference' => 'CMD-B']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['vehicule_id' => $vehiculeA->id]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-A', $response->json('data.0.reference'));
    }

    public function test_paginates_results(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        for ($i = 0; $i < 5; $i++) {
            $this->makeCommande($org, $vehicule, ['reference' => "CMD-$i"]);
        }

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['per_page' => 2]))->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_returns_empty_list_when_no_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_forbidden_for_staff_only_account(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine'))->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('client.activite.mine'))->assertStatus(401);
    }
}
