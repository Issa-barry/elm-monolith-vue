<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientVehicle;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ClientVehicleTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['clients.read', 'clients.update']);
    }

    private function makeClient(?Organization $org = null): Client
    {
        return Client::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
    }

    private function makeVehicle(Client $client, array $overrides = []): ClientVehicle
    {
        return ClientVehicle::create(array_merge([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'nom_vehicule' => 'Camion 1',
        ], $overrides));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_vehicle_and_redirects(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)
            ->post(route('clients.vehicules.store', $client), [
                'nom_vehicule' => 'Camion Partenaire',
                'immatriculation' => 'AB-123-CD',
            ])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('client_vehicules', [
            'client_id' => $client->id,
            'nom_vehicule' => 'Camion Partenaire',
        ]);
    }

    public function test_store_allows_all_fields_optional(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)
            ->post(route('clients.vehicules.store', $client), [])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('client_vehicules', ['client_id' => $client->id]);
    }

    public function test_store_normalizes_chauffeur_telephone(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)
            ->post(route('clients.vehicules.store', $client), [
                'chauffeur_nom' => 'Mamadou',
                'chauffeur_telephone' => '620000001',
                'chauffeur_code_pays' => 'GN',
            ])
            ->assertRedirect(route('clients.edit', $client));

        $vehicule = ClientVehicle::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('GN', $vehicule->chauffeur_code_pays);
        $this->assertNotNull($vehicule->chauffeur_telephone);
    }

    public function test_store_rejects_invalid_chauffeur_telephone(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)
            ->post(route('clients.vehicules.store', $client), [
                'chauffeur_telephone' => 'pas-un-numero',
                'chauffeur_code_pays' => 'GN',
            ])
            ->assertSessionHasErrors('chauffeur_telephone');
    }

    public function test_store_returns_403_for_other_organization(): void
    {
        $client = $this->makeClient(Organization::factory()->create());

        $this->actingAs($this->user)
            ->post(route('clients.vehicules.store', $client), ['nom_vehicule' => 'X'])
            ->assertStatus(403);
    }

    public function test_store_returns_403_without_clients_update_permission(): void
    {
        $this->initOrgAndUser(['clients.read']);
        $client = $this->makeClient();

        $this->actingAs($this->user)
            ->post(route('clients.vehicules.store', $client), ['nom_vehicule' => 'X'])
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_vehicle(): void
    {
        $client = $this->makeClient();
        $vehicule = $this->makeVehicle($client);

        $this->actingAs($this->user)
            ->put(route('clients.vehicules.update', [$client, $vehicule]), [
                'nom_vehicule' => 'Camion Modifié',
            ])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertSame('Camion Modifié', $vehicule->fresh()->nom_vehicule);
    }

    public function test_update_returns_404_when_vehicle_belongs_to_another_client(): void
    {
        $client = $this->makeClient();
        $otherClient = $this->makeClient();
        $vehicule = $this->makeVehicle($otherClient);

        $this->actingAs($this->user)
            ->put(route('clients.vehicules.update', [$client, $vehicule]), [
                'nom_vehicule' => 'Camion Modifié',
            ])
            ->assertStatus(404);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = $this->makeClient($otherOrg);
        $vehicule = $this->makeVehicle($client);

        $this->actingAs($this->user)
            ->put(route('clients.vehicules.update', [$client, $vehicule]), [
                'nom_vehicule' => 'Camion Modifié',
            ])
            ->assertStatus(403);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_vehicle(): void
    {
        $client = $this->makeClient();
        $vehicule = $this->makeVehicle($client);

        $this->actingAs($this->user)
            ->delete(route('clients.vehicules.destroy', [$client, $vehicule]))
            ->assertRedirect(route('clients.edit', $client));

        $this->assertSoftDeleted('client_vehicules', ['id' => $vehicule->id]);
    }

    public function test_destroy_returns_404_when_vehicle_belongs_to_another_client(): void
    {
        $client = $this->makeClient();
        $otherClient = $this->makeClient();
        $vehicule = $this->makeVehicle($otherClient);

        $this->actingAs($this->user)
            ->delete(route('clients.vehicules.destroy', [$client, $vehicule]))
            ->assertStatus(404);

        $this->assertDatabaseHas('client_vehicules', ['id' => $vehicule->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = $this->makeClient($otherOrg);
        $vehicule = $this->makeVehicle($client);

        $this->actingAs($this->user)
            ->delete(route('clients.vehicules.destroy', [$client, $vehicule]))
            ->assertStatus(403);
    }
}
