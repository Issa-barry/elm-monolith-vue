<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CommandeVenteTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    private function userWithPermissions(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach (['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete']);

        return $user;
    }

    private function makeContext(Organization $org): array
    {
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Depot Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Rouleau',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_vente' => 2000,
            'prix_usine' => 1500,
        ]);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $client = Client::factory()->create(['organization_id' => $org->id]);

        return compact('site', 'produit', 'vehicule', 'client');
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('ventes.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('ventes.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('ventes.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('ventes.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_commande_with_vehicule_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        ['site' => $site, 'produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($org);

        $response = $this->actingAs($user)
            ->post(route('ventes.store'), [
                'site_id' => $site->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    [
                        'produit_id' => $produit->id,
                        'qte' => 2,
                        'prix_vente' => 2000,
                    ],
                ],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
        ]);
    }

    public function test_store_creates_commande_with_client_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        ['site' => $site, 'produit' => $produit, 'client' => $client] = $this->makeContext($org);

        $this->actingAs($user)
            ->post(route('ventes.store'), [
                'site_id' => $site->id,
                'client_id' => $client->id,
                'lignes' => [
                    [
                        'produit_id' => $produit->id,
                        'qte' => 1,
                        'prix_vente' => 1500,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'organization_id' => $org->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_store_fails_without_vehicule_or_client(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        ['site' => $site, 'produit' => $produit] = $this->makeContext($org);

        $this->actingAs($user)
            ->post(route('ventes.store'), [
                'site_id' => $site->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors();
    }

    public function test_store_fails_with_empty_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('ventes.store'), [])
            ->assertSessionHasErrors(['site_id', 'lignes']);
    }

    public function test_store_fails_with_empty_lignes(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        ['site' => $site, 'vehicule' => $vehicule] = $this->makeContext($org);

        $this->actingAs($user)
            ->post(route('ventes.store'), [
                'site_id' => $site->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [],
            ])
            ->assertSessionHasErrors('lignes');
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('ventes.show', $commande))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $commande = CommandeVente::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($user)
            ->get(route('ventes.show', $commande))
            ->assertStatus(403);
    }

    // ── annuler ───────────────────────────────────────────────────────────────

    public function test_annuler_sets_statut_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->actingAs($user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation' => 'Annulation test',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_fails_without_motif(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->actingAs($user)
            ->patch(route('ventes.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation');
    }

    public function test_annuler_returns_422_if_already_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'statut' => StatutCommandeVente::ANNULEE,
        ]);

        $this->actingAs($user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation' => 'Tentative double annulation',
            ])
            ->assertStatus(422);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_annulee_commande_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'statut' => StatutCommandeVente::ANNULEE,
        ]);

        $this->actingAs($user)
            ->delete(route('ventes.destroy', $commande))
            ->assertRedirect(route('ventes.index'));

        $this->assertSoftDeleted('commandes_ventes', ['id' => $commande->id]);
    }

    public function test_destroy_returns_403_for_non_annulee_commande(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->actingAs($user)
            ->delete(route('ventes.destroy', $commande))
            ->assertStatus(403);
    }

    // ── annuler with encaissement ─────────────────────────────────────────────

    public function test_annuler_returns_422_if_encaissement_exists(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        ['site' => $site, 'produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $facture = \App\Models\FactureVente::create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 5000,
            'montant_net' => 5000,
        ]);

        \App\Models\EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 5000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->actingAs($user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation' => 'Test annulation avec encaissement',
            ])
            ->assertStatus(422);
    }
}
