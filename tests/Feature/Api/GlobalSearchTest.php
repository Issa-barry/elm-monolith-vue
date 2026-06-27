<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->staffUser = $this->makeStaffUser([
            'clients.read', 'ventes.read', 'factures.read', 'vehicules.read',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeStaffUser(array $permissions): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeRoleUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeVehicule(array $overrides = []): Vehicule
    {
        return Vehicule::create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'nom_vehicule' => 'Camion Test',
            'immatriculation' => 'GN-001-AA',
            'is_active' => true,
        ], $overrides));
    }

    private function makeClient(array $overrides = []): Client
    {
        $clientUser = User::factory()->create(['organization_id' => $this->org->id]);

        return Client::create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $clientUser->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '620000001',
            'is_active' => true,
        ], $overrides));
    }

    private function makeCommande(Client $client, Vehicule $vehicule, array $overrides = []): CommandeVente
    {
        return CommandeVente::create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'client_id' => $client->id,
            'vehicule_id' => $vehicule->id,
            'reference' => 'CMD-TEST-001',
            'total_commande' => 10000,
            'statut' => 'livree',
        ], $overrides));
    }

    private function makeFacture(CommandeVente $commande, array $overrides = []): FactureVente
    {
        return FactureVente::create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'commande_vente_id' => $commande->id,
            'reference' => 'FAC-TEST-001',
            'montant_brut' => 10000,
            'montant_net' => 9500,
            'statut_facture' => 'payee',
        ], $overrides));
    }

    // ── Auth / validation ─────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson(route('api.search.global', ['q' => 'test']))
            ->assertUnauthorized();
    }

    public function test_missing_query_returns_422(): void
    {
        Sanctum::actingAs($this->staffUser, ['*']);

        $this->getJson(route('api.search.global'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_short_query_returns_422(): void
    {
        Sanctum::actingAs($this->staffUser, ['*']);

        $this->getJson(route('api.search.global', ['q' => 'a']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    // ── Staff user ────────────────────────────────────────────────────────────

    public function test_staff_sees_all_four_categories(): void
    {
        Sanctum::actingAs($this->staffUser, ['*']);

        $response = $this->getJson(route('api.search.global', ['q' => 'ab']))
            ->assertOk()
            ->assertJsonStructure(['query', 'took_ms', 'results' => []]);

        $results = $response->json('results');
        $this->assertArrayHasKey('clients', $results);
        $this->assertArrayHasKey('commandes', $results);
        $this->assertArrayHasKey('factures', $results);
        $this->assertArrayHasKey('vehicules', $results);
    }

    public function test_staff_results_are_org_scoped(): void
    {
        Sanctum::actingAs($this->staffUser, ['*']);

        $this->makeClient(['nom' => 'Baba', 'prenom' => 'OrgTest']);

        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        Client::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'nom' => 'Baba',
            'prenom' => 'AutreOrg',
            'telephone' => '621000000',
            'is_active' => true,
        ]);

        $response = $this->getJson(route('api.search.global', ['q' => 'Baba']))
            ->assertOk();

        $items = $response->json('results.clients.items');
        $this->assertCount(1, $items);
        $this->assertSame('OrgTest Baba', $items[0]['title']);
    }

    // ── categories filter ─────────────────────────────────────────────────────

    public function test_categories_filter_restricts_to_requested_providers(): void
    {
        Sanctum::actingAs($this->staffUser, ['*']);

        $this->makeClient(['nom' => 'Diallo', 'prenom' => 'Fitri']);

        $response = $this->getJson(route('api.search.global', ['q' => 'Diallo', 'categories' => ['clients']]))
            ->assertOk();

        $results = $response->json('results');
        $this->assertArrayHasKey('clients', $results);
        $this->assertArrayNotHasKey('commandes', $results);
        $this->assertArrayNotHasKey('factures', $results);
        $this->assertArrayNotHasKey('vehicules', $results);
    }

    // ── client role ───────────────────────────────────────────────────────────

    public function test_client_role_sees_only_own_commandes_and_factures(): void
    {
        $clientUser = $this->makeRoleUser('client');
        $client = Client::create([
            'organization_id' => $this->org->id,
            'user_id' => $clientUser->id,
            'nom' => 'Cissé',
            'prenom' => 'ClientTest',
            'telephone' => '622000001',
            'is_active' => true,
        ]);

        $vehicule = $this->makeVehicule();
        $commande = $this->makeCommande($client, $vehicule, ['reference' => 'CMD-CISSE-001']);
        $this->makeFacture($commande, ['reference' => 'FAC-CISSE-001']);

        $otherClient = $this->makeClient(['nom' => 'Sylla', 'prenom' => 'Autre']);
        $this->makeCommande($otherClient, $vehicule, ['reference' => 'CMD-CISSE-002']);

        Sanctum::actingAs($clientUser, ['*']);

        $response = $this->getJson(route('api.search.global', ['q' => 'CISSE']))
            ->assertOk();

        $results = $response->json('results');
        $this->assertArrayNotHasKey('clients', $results);
        $this->assertArrayNotHasKey('vehicules', $results);

        $this->assertArrayHasKey('commandes', $results);
        $refs = array_column($results['commandes']['items'], 'title');
        $this->assertContains('CMD-CISSE-001', $refs);
        $this->assertNotContains('CMD-CISSE-002', $refs);

        $this->assertArrayHasKey('factures', $results);
        $factureRefs = array_column($results['factures']['items'], 'title');
        $this->assertContains('FAC-CISSE-001', $factureRefs);
    }

    // ── proprietaire role ─────────────────────────────────────────────────────

    public function test_proprietaire_role_sees_only_own_vehicules(): void
    {
        $proprietaireUser = $this->makeRoleUser('proprietaire');
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $proprietaireUser->id,
        ]);

        $this->makeVehicule(['nom_vehicule' => 'Truck-Alpha', 'proprietaire_id' => $proprietaire->id]);
        $this->makeVehicule(['nom_vehicule' => 'Truck-Beta', 'immatriculation' => 'GN-002-BB']);

        Sanctum::actingAs($proprietaireUser, ['*']);

        $response = $this->getJson(route('api.search.global', ['q' => 'Truck']))
            ->assertOk();

        $results = $response->json('results');
        $this->assertArrayNotHasKey('clients', $results);
        $this->assertArrayNotHasKey('commandes', $results);
        $this->assertArrayNotHasKey('factures', $results);

        $this->assertArrayHasKey('vehicules', $results);
        $names = array_column($results['vehicules']['items'], 'title');
        $this->assertContains('Truck-Alpha', $names);
        $this->assertNotContains('Truck-Beta', $names);
    }

    // ── livreur role ──────────────────────────────────────────────────────────

    public function test_livreur_role_sees_only_commandes_via_own_equipe(): void
    {
        $livreurUser = $this->makeRoleUser('livreur');
        $livreur = Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $livreurUser->id,
        ]);

        $vehicule = $this->makeVehicule(['nom_vehicule' => 'Van-Livreur']);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        $client = $this->makeClient();
        $this->makeCommande($client, $vehicule, ['reference' => 'CMD-VAN-001']);

        $otherVehicule = $this->makeVehicule(['nom_vehicule' => 'Autre-Van', 'immatriculation' => 'GN-099-XX']);
        $this->makeCommande($client, $otherVehicule, ['reference' => 'CMD-VAN-002']);

        Sanctum::actingAs($livreurUser, ['*']);

        $response = $this->getJson(route('api.search.global', ['q' => 'CMD']))
            ->assertOk();

        $results = $response->json('results');
        $this->assertArrayNotHasKey('clients', $results);
        $this->assertArrayNotHasKey('factures', $results);
        $this->assertArrayNotHasKey('vehicules', $results);

        $this->assertArrayHasKey('commandes', $results);
        $refs = array_column($results['commandes']['items'], 'title');
        $this->assertContains('CMD-VAN-001', $refs);
        $this->assertNotContains('CMD-VAN-002', $refs);
    }
}
