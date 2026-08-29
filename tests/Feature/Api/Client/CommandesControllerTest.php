<?php

namespace Tests\Feature\Api\Client;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class CommandesControllerTest extends TestCase
{
    use HasProduitVariante, MakesClientProfiles, RefreshDatabase;

    private function makeClientUser(Organization $org): User
    {
        $this->ensureClientRoles();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');
        Client::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        return $user->fresh();
    }

    private function makeCommande(Organization $org, string $clientId, array $overrides = []): CommandeVente
    {
        return CommandeVente::factory()->create(array_merge([
            'organization_id' => $org->id,
            'client_id' => $clientId,
            'statut' => StatutCommandeVente::LIVREE->value,
            'validated_at' => now(),
        ], $overrides));
    }

    public function test_lists_only_the_authenticated_clients_commandes(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeClientUser($org);
        $this->makeCommande($org, $user->client->id, ['reference' => 'CMD-MINE']);

        $otherClient = Client::factory()->create(['organization_id' => $org->id]);
        $this->makeCommande($org, $otherClient->id, ['reference' => 'CMD-OTHER']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.commandes.mine'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-MINE', $response->json('data.0.reference'));
    }

    public function test_isolates_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $clientA = Client::factory()->create(['organization_id' => $orgA->id]);
        $this->makeCommande($orgA, $clientA->id);

        $orgB = Organization::factory()->create();
        $userB = $this->makeClientUser($orgB);

        Sanctum::actingAs($userB, ['*']);

        $this->getJson(route('client.commandes.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_returns_empty_list_for_a_pure_proprietaire_without_client_profile(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.commandes.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_multi_role_staff_and_client_sees_their_commandes(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = $this->makeClientUser($org);
        $user->assignRole('admin_entreprise');
        $this->makeCommande($org, $user->client->id, ['reference' => 'CMD-MULTI']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.commandes.mine'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-MULTI', $response->json('data.0.reference'));
    }

    public function test_filters_by_statut(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeClientUser($org);
        $this->makeCommande($org, $user->client->id, ['statut' => StatutCommandeVente::LIVREE->value, 'reference' => 'CMD-LIVREE']);
        $this->makeCommande($org, $user->client->id, ['statut' => StatutCommandeVente::A_CHARGER->value, 'reference' => 'CMD-ACHARGER']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.commandes.mine', ['statut' => StatutCommandeVente::A_CHARGER->value]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-ACHARGER', $response->json('data.0.reference'));
    }

    public function test_paginates_results(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeClientUser($org);
        for ($i = 0; $i < 5; $i++) {
            $this->makeCommande($org, $user->client->id, ['reference' => "CMD-$i"]);
        }

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.commandes.mine', ['per_page' => 2]))->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(5, $response->json('meta.total'));
    }

    public function test_show_returns_the_detail_with_lignes(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeClientUser($org);
        $commande = $this->makeCommande($org, $user->client->id);
        $produit = $this->makeProduitAvecVariante($org);
        CommandeVenteLigne::create([
            'commande_vente_id' => $commande->id,
            'variante_id' => $produit->variantes()->first()->id,
            'quantite_demandee' => 10,
            'libelle_snapshot' => 'Pack Eau 1.5L',
            'prix_usine_snapshot' => 4000,
            'prix_vente_snapshot' => 5000,
            'total_ligne' => 50000,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.commandes.show', $commande->id))
            ->assertOk()
            ->assertJsonPath('data.reference', $commande->reference)
            ->assertJsonPath('data.lignes.0.libelle', 'Pack Eau 1.5L')
            ->assertJsonPath('data.lignes.0.quantite_demandee', 10);
    }

    public function test_show_returns_404_for_another_clients_commande(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeClientUser($org);

        $otherClient = Client::factory()->create(['organization_id' => $org->id]);
        $otherCommande = $this->makeCommande($org, $otherClient->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.commandes.show', $otherCommande->id))->assertStatus(404);
    }

    public function test_show_returns_404_for_a_pure_proprietaire(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $commande = $this->makeCommande($org, $client->id);

        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.commandes.show', $commande->id))->assertStatus(404);
    }

    public function test_returns_empty_list_when_no_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeClientUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.commandes.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_forbidden_for_staff_only_account(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.commandes.mine'))->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('client.commandes.mine'))->assertStatus(401);
    }
}
