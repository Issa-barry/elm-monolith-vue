<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Parametre;
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
                'nom_complet' => 'Aissatou Diallo',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'is_active' => true,
            ]);

        $client = Client::where('organization_id', $this->org->id)
            ->where('nom_complet', 'Aissatou Diallo')
            ->firstOrFail();

        $response->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Aissatou Diallo',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_sets_flash_success_message(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Test Flash',
                'telephone' => '622000099',
                'code_pays' => 'GN',
            ])
            ->assertSessionHas('success', 'Client créé avec succès.');
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [])
            ->assertSessionHasErrors(['nom_complet', 'telephone', 'code_pays']);
    }

    public function test_store_fails_with_invalid_code_pays(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Aissatou Diallo',
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
                'nom_complet' => 'Client Test',
            ])
            ->assertStatus(403);
    }

    // ── règle pays = Guinée → ville = Conakry ─────────────────────────────────

    public function test_store_sets_conakry_when_pays_is_guinee_and_ville_empty(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Ibrahima Barry',
                'telephone' => '622000011',
                'code_pays' => 'GN',
                'ville' => '',
                'is_active' => true,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Ibrahima Barry',
            'ville' => 'Conakry',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_keeps_custom_ville_when_pays_is_guinee(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Fatoumata Camara',
                'telephone' => '622000012',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
                'is_active' => true,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Fatoumata Camara',
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
                'nom_complet' => 'Thierno Balde',
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
                'is_active' => true,
            ])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'nom_complet' => 'Thierno Balde',
        ]);
    }

    public function test_update_sets_flash_success_message(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom_complet' => 'Update Flash',
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
            ->assertSessionHasErrors(['nom_complet', 'telephone', 'code_pays']);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom_complet' => 'Mariama Barry',
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
                'nom_complet' => 'Mamadou Diallo',
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
                'nom_complet' => 'Mamadou Diallo',
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
                'nom_complet' => 'Kadiatou Barry',
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
                'nom_complet' => 'Mamadou Diallo',
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
                'nom_complet' => 'Mamadou Diallo',
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
                'nom_complet' => 'Mamadou Diallo',
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
                'nom_complet' => 'Alpha Toure',
                'telephone' => '622000020',
                'code_pays' => 'GN',
                'is_active' => false,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Alpha Toure',
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
                'nom_complet' => $client->nom_complet,
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

    // ── Nature du client : Revendeur → cashback automatique ─────────────────────

    public function test_store_force_cashback_eligible_pour_revendeur_meme_si_non_soumis(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Boutique Diallo',
                'telephone' => '622000030',
                'code_pays' => 'GN',
                'type' => 'revendeur',
                // cashback_eligible volontairement absent du payload (jamais soumis) — doit être
                // complété à true. Contrairement à un payload qui l'enverrait explicitement à
                // false (cf. test_update_tentative_de_desactiver_le_cashback_dun_revendeur_est_
                // refusee), qui doit lui être REJETÉ, pas silencieusement corrigé.
                'cashback_montant_par_pack' => 300,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Boutique Diallo',
            'type' => 'revendeur',
            'cashback_eligible' => true,
        ]);
    }

    public function test_update_force_cashback_eligible_pour_revendeur(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'externe',
            'cashback_eligible' => false,
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom_complet' => $client->nom_complet,
                'telephone' => '622000031',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'type' => 'revendeur',
                // cashback_eligible volontairement absent (cf. commentaire ci-dessus).
                'cashback_montant_par_pack' => 300,
            ]);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'type' => 'revendeur',
            'cashback_eligible' => true,
        ]);
    }

    public function test_store_distributeur_ne_force_pas_le_cashback(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Grossiste Barry',
                'telephone' => '622000032',
                'code_pays' => 'GN',
                'type' => 'distributeur',
                'cashback_eligible' => false,
            ]);

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Grossiste Barry',
            'type' => 'distributeur',
            'cashback_eligible' => false,
        ]);
    }

    // ── Cashback = commission par pack propre au client (CASHBACK-001/002) ──────

    public function test_store_revendeur_sans_montant_cashback_est_refuse(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Revendeur Sans Montant',
                'telephone' => '622000040',
                'code_pays' => 'GN',
                'type' => 'revendeur',
            ])
            ->assertSessionHasErrors('cashback_montant_par_pack');

        $this->assertDatabaseMissing('clients', ['nom_complet' => 'Revendeur Sans Montant']);
    }

    public function test_store_revendeur_avec_montant_cashback_positif_est_accepte(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Revendeur Avec Montant',
                'telephone' => '622000041',
                'code_pays' => 'GN',
                'type' => 'revendeur',
                'cashback_montant_par_pack' => 300,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Revendeur Avec Montant',
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 300,
        ]);
    }

    public function test_update_tentative_de_desactiver_le_cashback_dun_revendeur_est_refusee(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'revendeur',
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 300,
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom_complet' => $client->nom_complet,
                'telephone' => '622000042',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'type' => 'revendeur',
                'cashback_eligible' => false,
                'cashback_montant_par_pack' => 300,
            ])
            ->assertSessionHasErrors('cashback_eligible');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'cashback_eligible' => true]);
    }

    public function test_store_externe_avec_cashback_desactive_est_accepte(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Externe Sans Cashback',
                'telephone' => '622000043',
                'code_pays' => 'GN',
                'type' => 'externe',
                'cashback_eligible' => false,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Externe Sans Cashback',
            'cashback_eligible' => false,
        ]);
    }

    public function test_store_externe_avec_cashback_actif_sans_montant_est_refuse(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Externe Cashback Incomplet',
                'telephone' => '622000044',
                'code_pays' => 'GN',
                'type' => 'externe',
                'cashback_eligible' => true,
            ])
            ->assertSessionHasErrors('cashback_montant_par_pack');

        $this->assertDatabaseMissing('clients', ['nom_complet' => 'Externe Cashback Incomplet']);
    }

    public function test_store_externe_avec_cashback_actif_et_montant_est_accepte(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Externe Cashback Complet',
                'telephone' => '622000045',
                'code_pays' => 'GN',
                'type' => 'externe',
                'cashback_eligible' => true,
                'cashback_montant_par_pack' => 250,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Externe Cashback Complet',
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 250,
        ]);
    }

    // ── Configuration cashback depuis la fiche client ────────────────────────

    public function test_update_cashback_depuis_la_fiche_active_le_cashback(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'externe',
            'cashback_eligible' => false,
            'cashback_montant_par_pack' => null,
        ]);

        $this->actingAs($this->user)
            ->from(route('clients.show', $client))
            ->patch(route('clients.cashback.update', $client), [
                'cashback_eligible' => true,
                'cashback_montant_par_pack' => 450,
            ])
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('success', 'Configuration cashback mise à jour.');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 450,
        ]);
    }

    public function test_update_cashback_depuis_la_fiche_refuse_un_montant_absent(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'externe',
            'cashback_eligible' => false,
            'cashback_montant_par_pack' => null,
        ]);

        $this->actingAs($this->user)
            ->patch(route('clients.cashback.update', $client), [
                'cashback_eligible' => true,
                'cashback_montant_par_pack' => null,
            ])
            ->assertSessionHasErrors('cashback_montant_par_pack');

        $this->assertFalse($client->fresh()->cashback_eligible);
    }

    public function test_update_cashback_depuis_la_fiche_refuse_de_desactiver_un_revendeur(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'revendeur',
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 300,
        ]);

        $this->actingAs($this->user)
            ->patch(route('clients.cashback.update', $client), [
                'cashback_eligible' => false,
                'cashback_montant_par_pack' => 300,
            ])
            ->assertSessionHasErrors('cashback_eligible');

        $this->assertTrue($client->fresh()->cashback_eligible);
    }

    public function test_update_cashback_depuis_la_fiche_est_isole_par_organisation(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => $otherOrg->id,
            'type' => 'externe',
            'cashback_eligible' => false,
        ]);

        $this->actingAs($this->user)
            ->patch(route('clients.cashback.update', $client), [
                'cashback_eligible' => true,
                'cashback_montant_par_pack' => 450,
            ])
            ->assertStatus(403);

        $this->assertFalse($client->fresh()->cashback_eligible);
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
                'nom_complet' => 'Mariama Sylla',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
            ])
            ->assertRedirect(); // redirige vers edit du nouveau client

        $this->assertDatabaseHas('clients', [
            'nom_complet' => 'Mariama Sylla',
            'deleted_at' => null,
        ]);
    }

    // ── derogation-impayes (fiche client, cf. Clients/Show.vue) ─────────────────
    // Même règle de cohérence que le véhicule (DerogationImpayesService, mutualisée) — cf.
    // VehiculeTest::test_update_derogation_*() pour la version véhicule de ces mêmes scénarios.

    public function test_edit_expose_la_derogation_et_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 300_000);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $client->update(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 1_200_000]);

        $this->actingAs($this->user)
            ->get(route('clients.edit', $client))
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Edit')
                ->where('client.derogation_impayes_autorisee', true)
                ->where('client.seuil_derogation_impayes', 1_200_000)
                ->where('seuil_global_impayes', 300_000)
            );
    }

    public function test_show_expose_le_plafond_propre_du_client(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $client->update(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 4_000_000]);

        $this->actingAs($this->user)
            ->get(route('clients.show', $client))
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Show')
                ->where('client.derogation_impayes_autorisee', true)
                ->where('client.seuil_derogation_impayes', 4_000_000)
            );
    }

    public function test_update_derogation_active_avec_un_plafond_puis_desactive(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => true,
                'seuil_derogation_impayes' => 5_000_000,
            ])
            ->assertRedirect();
        $client->refresh();
        $this->assertTrue($client->derogation_impayes_autorisee);
        $this->assertSame(5_000_000, $client->seuil_derogation_impayes);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => false,
            ])
            ->assertRedirect();
        $this->assertFalse($client->fresh()->derogation_impayes_autorisee);
    }

    /** Même règle que le véhicule : pas de dérogation sans plafond valide. */
    public function test_update_derogation_refuse_lactivation_sans_plafond(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => true,
            ])
            ->assertSessionHasErrors('derogation_impayes_autorisee');

        $this->assertFalse($client->fresh()->derogation_impayes_autorisee);
    }

    /** Même règle que le véhicule : plafond inférieur au seuil standard refusé. */
    public function test_update_derogation_refuse_un_plafond_inferieur_au_seuil_standard(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 3_000_000);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => true,
                'seuil_derogation_impayes' => 2_000_000,
            ])
            ->assertSessionHasErrors('derogation_impayes_autorisee');

        $this->assertFalse($client->fresh()->derogation_impayes_autorisee);
    }

    /** Désactiver reste toujours possible, sans condition de plafond. */
    public function test_update_derogation_desactive_sans_condition(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $client->update(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 5_000_000]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($client->fresh()->derogation_impayes_autorisee);
    }

    /**
     * Désactiver la dérogation sans renvoyer de plafond conserve le plafond déjà enregistré en
     * base — il n'est simplement plus appliqué au calcul (cf. SolvabiliteService), pour faciliter
     * une éventuelle réactivation ultérieure sans ressaisie.
     */
    public function test_update_derogation_desactivation_conserve_le_plafond_deja_enregistre(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $client->update(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 5_000_000]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => false,
            ])
            ->assertRedirect();

        $client->refresh();
        $this->assertFalse($client->derogation_impayes_autorisee);
        $this->assertSame(5_000_000, $client->seuil_derogation_impayes);
    }

    /**
     * Réactiver sans renvoyer de nouveau plafond réutilise celui déjà enregistré en base — pas
     * besoin de le ressaisir.
     */
    public function test_update_derogation_reactivation_sans_nouveau_plafond_reutilise_lancien(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $client->update([
            'derogation_impayes_autorisee' => false,
            'seuil_derogation_impayes' => 5_000_000,
        ]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => true,
            ])
            ->assertRedirect();

        $client->refresh();
        $this->assertTrue($client->derogation_impayes_autorisee);
        $this->assertSame(5_000_000, $client->seuil_derogation_impayes);
    }

    /** Un plafond fourni au moment de l'activation remplace celui éventuellement déjà enregistré. */
    public function test_update_derogation_avec_nouveau_plafond_remplace_lancien(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $client->update(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 5_000_000]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => true,
                'seuil_derogation_impayes' => 7_500_000,
            ])
            ->assertRedirect();

        $this->assertSame(7_500_000, $client->fresh()->seuil_derogation_impayes);
    }

    public function test_update_derogation_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->patch(route('clients.derogation-impayes.update', $client), [
                'derogation_impayes_autorisee' => true,
                'seuil_derogation_impayes' => 5_000_000,
            ])
            ->assertStatus(403);
    }
}
