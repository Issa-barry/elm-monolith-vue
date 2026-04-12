<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['clients.read', 'clients.create', 'clients.update', 'clients.delete']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('clients.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('clients.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('clients.create'))
            ->assertStatus(200);
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('clients.create'))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_client_and_redirects_to_edit(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Aissatou',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'is_active' => true,
            ]);

        $client = Client::where('organization_id', $this->org->id)
            ->where('nom', 'DIALLO')
            ->firstOrFail();

        $response->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('clients', [
            'nom' => 'DIALLO',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_sets_flash_success_message(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Flash',
                'prenom' => 'Test',
                'telephone' => '622000099',
                'code_pays' => 'GN',
            ])
            ->assertSessionHas('success', 'Client créé avec succès.');
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'telephone', 'code_pays']);
    }

    public function test_store_fails_with_invalid_code_pays(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Aissatou',
                'telephone' => '622000001',
                'code_pays' => 'XX',
            ])
            ->assertSessionHasErrors('code_pays');
    }

    public function test_store_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->post(route('clients.store'), [
                'nom' => 'Test',
                'prenom' => 'Client',
            ])
            ->assertStatus(403);
    }

    // ── règle pays = Guinée → ville = Conakry ─────────────────────────────────

    public function test_store_sets_conakry_when_pays_is_guinee_and_ville_empty(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Barry',
                'prenom' => 'Ibrahima',
                'telephone' => '622000011',
                'code_pays' => 'GN',
                'ville' => '',
                'is_active' => true,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom' => 'BARRY',
            'ville' => 'Conakry',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_keeps_custom_ville_when_pays_is_guinee(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Camara',
                'prenom' => 'Fatoumata',
                'telephone' => '622000012',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
                'is_active' => true,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom' => 'CAMARA',
            'ville' => 'Kindia',
        ]);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get(route('clients.show', $client))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->get(route('clients.show', $client))
            ->assertStatus(403);
    }

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get(route('clients.edit', $client))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->get(route('clients.edit', $client))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_client_and_redirects_to_edit(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom' => 'Balde',
                'prenom' => 'Thierno',
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
                'is_active' => true,
            ])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'nom' => 'BALDE',
        ]);
    }

    public function test_update_sets_flash_success_message(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom' => 'Flash',
                'prenom' => 'Update',
                'telephone' => '622000088',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => true,
            ])
            ->assertSessionHas('success', 'Client mis à jour avec succès.');
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'telephone', 'code_pays']);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom' => 'Barry',
                'prenom' => 'Mariama',
            ])
            ->assertStatus(403);
    }

    // ── unicité par organisation ──────────────────────────────────────────────

    public function test_store_refuses_duplicate_telephone_in_same_org(): void
    {
        Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);

        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000001', // même numéro, format local → canonique +224622000001
                'code_pays' => 'GN',
                'ville' => 'Conakry',
            ])
            ->assertSessionHasErrors('telephone');
    }

    public function test_store_refuses_duplicate_email_in_same_org(): void
    {
        Client::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'client@example.com',
            'telephone' => '+224622000001',
        ]);

        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'email' => 'client@example.com',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_store_allows_same_telephone_in_different_org(): void
    {
        $otherOrg = Organization::factory()->create();
        Client::factory()->create([
            'organization_id' => $otherOrg->id,
            'telephone' => '+224622000001',
        ]);

        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Barry',
                'prenom' => 'Kadiatou',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
            ])
            ->assertRedirect(); // Redirige vers edit (création réussie)
    }

    public function test_update_allows_same_client_to_keep_telephone(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000001', // son propre numéro → doit passer
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => true,
            ])
            ->assertRedirect(route('clients.edit', $client));
    }

    public function test_update_refuses_telephone_conflict_with_other_client(): void
    {
        Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000002',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
        ]);

        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('telephone');
    }

    public function test_update_refuses_email_conflict_with_other_client(): void
    {
        Client::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'taken@example.com',
            'telephone' => '+224622000002',
        ]);

        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'email' => 'taken@example.com',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('email');
    }

    // ── statut is_active ──────────────────────────────────────────────────────

    public function test_store_creates_inactive_client(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Toure',
                'prenom' => 'Alpha',
                'telephone' => '622000020',
                'code_pays' => 'GN',
                'is_active' => false,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom' => 'TOURE',
            'is_active' => false,
        ]);
    }

    public function test_update_toggles_is_active(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom' => $client->nom,
                'prenom' => $client->prenom,
                'telephone' => '622000003',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => false,
            ])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_active' => false,
        ]);
    }

    // ── archivage (soft delete) ───────────────────────────────────────────────

    public function test_destroy_soft_deletes_client_and_redirects(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->delete(route('clients.destroy', $client))
            ->assertRedirect(route('clients.index'));

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_destroy_sets_flash_success_message(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->delete(route('clients.destroy', $client))
            ->assertSessionHas('success', 'Client supprimé.');
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->delete(route('clients.destroy', $client))
            ->assertStatus(403);
    }

    public function test_destroy_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();
        $client = Client::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->delete(route('clients.destroy', $client))
            ->assertStatus(403);
    }

    public function test_soft_deleted_client_not_visible_in_index(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $client->delete();

        $response = $this->actingAs($this->user)
            ->get(route('clients.index'));

        $response->assertStatus(200);

        $clients = $response->original->getData()['page']['props']['clients'] ?? [];
        $ids = array_column($clients, 'id');
        $this->assertNotContains($client->id, $ids);
    }

    public function test_soft_deleted_telephone_can_be_reused(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);
        $client->delete();

        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom' => 'Sylla',
                'prenom' => 'Mariama',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
            ])
            ->assertRedirect(); // redirige vers edit du nouveau client

        $this->assertDatabaseHas('clients', [
            'nom' => 'SYLLA',
            'deleted_at' => null,
        ]);
    }
}
