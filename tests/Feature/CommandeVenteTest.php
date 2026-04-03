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
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class CommandeVenteTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private Organization $org;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org  = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions(
            $this->org,
            ['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete'],
        );
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
        $this->actingAs($this->user)
            ->get(route('ventes.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('ventes.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('ventes.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('ventes.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_commande_with_vehicule_and_redirects(): void
    {
        ['site' => $site, 'produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $response = $this->actingAs($this->user)
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
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
        ]);
    }

    public function test_store_creates_commande_with_client_and_redirects(): void
    {
        ['site' => $site, 'produit' => $produit, 'client' => $client] = $this->makeContext($this->org);

        $this->actingAs($this->user)
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
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_store_fails_without_vehicule_or_client(): void
    {
        ['site' => $site, 'produit' => $produit] = $this->makeContext($this->org);

        $this->actingAs($this->user)
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
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [])
            ->assertSessionHasErrors(['site_id', 'lignes']);
    }

    public function test_store_fails_with_empty_lignes(): void
    {
        ['site' => $site, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)
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
        $commande = CommandeVente::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $commande = CommandeVente::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertStatus(403);
    }

    // ── annuler ───────────────────────────────────────────────────────────────

    public function test_annuler_sets_statut_annulee(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation' => 'Annulation test',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_fails_without_motif(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation');
    }

    public function test_annuler_returns_422_if_already_annulee(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::ANNULEE,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation' => 'Tentative double annulation',
            ])
            ->assertStatus(422);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_annulee_commande_and_redirects(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::ANNULEE,
        ]);

        $this->actingAs($this->user)
            ->delete(route('ventes.destroy', $commande))
            ->assertRedirect(route('ventes.index'));

        $this->assertSoftDeleted('commandes_ventes', ['id' => $commande->id]);
    }

    public function test_destroy_returns_403_for_non_annulee_commande(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->delete(route('ventes.destroy', $commande))
            ->assertStatus(403);
    }

    // ── annuler with encaissement ─────────────────────────────────────────────

    public function test_annuler_returns_422_if_encaissement_exists(): void
    {
        ['site' => $site] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $facture = \App\Models\FactureVente::create([
            'organization_id' => $this->org->id,
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

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation' => 'Test annulation avec encaissement',
            ])
            ->assertStatus(422);
    }
}
