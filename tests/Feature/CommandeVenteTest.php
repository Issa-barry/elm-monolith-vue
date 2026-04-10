<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommandeVenteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete']);

        // Attacher un site par défaut pour passer le middleware RequireSiteAssigned
        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeContext(Organization $org): array
    {
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
            'capacite_packs' => 2,
        ]);

        $client = Client::factory()->create(['organization_id' => $org->id]);

        return compact('produit', 'vehicule', 'client');
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

    public function test_create_exposes_vehicule_capacity_in_inertia_props(): void
    {
        ['vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->get(route('ventes.create'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Create')
                ->where('vehicules.0.id', $vehicule->id)
                ->where('vehicules.0.capacite_packs', (int) $vehicule->capacite_packs)
            );
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_commande_with_vehicule_and_redirects(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $response = $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'brouillon',
        ]);
    }

    public function test_store_creates_commande_with_client_and_redirects(): void
    {
        ['produit' => $produit, 'client' => $client] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 1500],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'statut' => 'brouillon',
        ]);
    }

    public function test_store_fails_without_vehicule_or_client(): void
    {
        ['produit' => $produit] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors();
    }

    public function test_store_fails_with_empty_lignes(): void
    {
        ['vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [])
            ->assertSessionHasErrors(['lignes']);
    }

    public function test_store_fails_when_total_quantite_differs_from_vehicule_capacity(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $vehicule->update(['capacite_packs' => 5]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseMissing('commandes_ventes', [
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 4000,
        ]);
    }

    public function test_edit_exposes_vehicule_capacity_in_inertia_props(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule, 'client' => $client] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);

        $commande->lignes()->create([
            'produit_id' => $produit->id,
            'qte' => 2,
            'prix_usine_snapshot' => (float) $produit->prix_usine,
            'prix_vente_snapshot' => (float) $produit->prix_vente,
            'total_ligne' => 2 * (float) $produit->prix_vente,
        ]);

        $this->actingAs($this->user)
            ->get(route('ventes.edit', $commande))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Edit')
                ->where('vehicules.0.id', $vehicule->id)
                ->where('vehicules.0.capacite_packs', (int) $vehicule->capacite_packs)
            );
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

    // ── valider : BROUILLON → EN_COURS ───────────────────────────────────────

    public function test_valider_transitions_brouillon_to_en_cours(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 5000,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.valider', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::EN_COURS, $commande->fresh()->statut);
    }

    public function test_valider_creates_facture_on_validation(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 8000,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.valider', $commande))
            ->assertRedirect();

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_brut' => 8000,
        ]);
    }

    // ── annuler : EN_COURS → ANNULEE ─────────────────────────────────────────

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

    public function test_annuler_returns_422_if_encaissement_exists(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 5000,
            'montant_net' => 5000,
        ]);

        EncaissementVente::create([
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

    // ── auto-clôture : EN_COURS → CLOTUREE ───────────────────────────────────

    public function test_auto_cloture_when_facture_fully_paid_and_no_commissions(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'statut' => StatutCommandeVente::EN_COURS,
            'total_commande' => 5000,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 5000,
            'montant_net' => 5000,
        ]);

        // Ajouter un encaissement qui solde entièrement la facture
        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 5000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        // La commande doit être automatiquement clôturée
        $this->assertEquals(StatutCommandeVente::CLOTUREE, $commande->fresh()->statut);
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
}
