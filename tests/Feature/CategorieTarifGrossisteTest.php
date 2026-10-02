<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Models\Categorie;
use App\Models\CategorieTarifGrossiste;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Tarifs Grossiste PROPRES À CHAQUE CLIENT (décision produit du 05/09/2026) — gérés depuis la
 * fiche du client, gatés par la policy Client (clients.read/clients.update + même organisation),
 * jamais une permission d'administration séparée. Cf. docs/grossiste.md.
 */
class CategorieTarifGrossisteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['clients.read', 'clients.update', 'ventes.create']);
    }

    private function makeGrossiste(): Client
    {
        return Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
    }

    private function makeCategorie(array $overrides = []): Categorie
    {
        return Categorie::create(array_merge([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille d\'eau',
            'statut' => 'actif',
        ], $overrides));
    }

    // ── forClient (fetch live) ───────────────────────────────────────────────

    public function test_for_client_returns_200_for_authorized_user(): void
    {
        $client = $this->makeGrossiste();

        $this->actingAs($this->user)
            ->getJson(route('clients.tarifs-grossiste.show', $client))
            ->assertOk();
    }

    public function test_for_client_returns_les_tarifs_de_ce_client_uniquement(): void
    {
        $client = $this->makeGrossiste();
        $autreClient = $this->makeGrossiste();
        $categorie = $this->makeCategorie();

        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'categorie_id' => $categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 18500,
        ]);
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $autreClient->id,
            'categorie_id' => $categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 18700,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('clients.tarifs-grossiste.show', $client));

        $response->assertOk();
        $tarifs = $response->json('tarifs');
        $this->assertCount(1, $tarifs);
        $this->assertSame(18500, $tarifs[0]['prix']);
    }

    public function test_for_client_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => $otherOrg->id,
            'type' => ClientType::GROSSISTE->value,
        ]);

        $this->actingAs($this->user)
            ->getJson(route('clients.tarifs-grossiste.show', $client))
            ->assertStatus(403);
    }

    public function test_for_client_returns_403_without_ventes_permission(): void
    {
        $user = $this->makeAdminUser();
        $client = Client::factory()->create([
            'organization_id' => $user->organization_id,
            'type' => ClientType::GROSSISTE->value,
        ]);

        $this->actingAs($user)
            ->getJson(route('clients.tarifs-grossiste.show', $client))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_upserts_les_tarifs_du_client(): void
    {
        $client = $this->makeGrossiste();
        $categorie = $this->makeCategorie();

        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), [
                'tarifs' => [
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::ENLEVEMENT->value, 'prix' => 18500],
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::LIVRAISON->value, 'prix' => 19000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
            'categorie_id' => $categorie->id,
            'mode' => 'enlevement',
            'prix' => 18500,
        ]);
        $this->assertDatabaseHas('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
            'categorie_id' => $categorie->id,
            'mode' => 'livraison',
            'prix' => 19000,
        ]);
    }

    public function test_update_ne_touche_pas_les_tarifs_dun_autre_client(): void
    {
        $client = $this->makeGrossiste();
        $autreClient = $this->makeGrossiste();
        $categorie = $this->makeCategorie();

        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $autreClient->id,
            'categorie_id' => $categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 18700,
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), [
                'tarifs' => [
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::ENLEVEMENT->value, 'prix' => 18500],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
            'prix' => 18500,
        ]);
        $this->assertDatabaseHas('categorie_tarifs_grossiste', [
            'client_id' => $autreClient->id,
            'prix' => 18700,
        ]);
    }

    public function test_update_supprime_un_tarif_dont_la_ligne_a_ete_retiree(): void
    {
        $client = $this->makeGrossiste();
        $categorie = $this->makeCategorie();
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'categorie_id' => $categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 18500,
        ]);
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'categorie_id' => $categorie->id,
            'mode' => ModeRemiseGrossiste::LIVRAISON->value,
            'prix' => 19000,
        ]);

        // Seule la ligne Enlèvement est resoumise — Livraison a été retirée côté UI.
        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), [
                'tarifs' => [
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::ENLEVEMENT->value, 'prix' => 18500],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
            'mode' => 'enlevement',
        ]);
        $this->assertDatabaseMissing('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
            'mode' => 'livraison',
        ]);
    }

    public function test_update_avec_tarifs_vide_supprime_tous_les_tarifs_du_client(): void
    {
        $client = $this->makeGrossiste();
        $categorie = $this->makeCategorie();
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'categorie_id' => $categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 18500,
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), ['tarifs' => []])
            ->assertRedirect();

        $this->assertDatabaseMissing('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
        ]);
    }

    public function test_update_remplace_un_tarif_existant_sans_dupliquer(): void
    {
        $client = $this->makeGrossiste();
        $categorie = $this->makeCategorie();
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'categorie_id' => $categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 15000,
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), [
                'tarifs' => [
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::ENLEVEMENT->value, 'prix' => 18500],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(1, CategorieTarifGrossiste::where('client_id', $client->id)->count());
        $this->assertDatabaseHas('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
            'mode' => 'enlevement',
            'prix' => 18500,
        ]);
    }

    public function test_update_bloque_si_le_tarif_ne_couvre_pas_le_cout_de_reference_dun_produit_de_la_categorie(): void
    {
        $client = $this->makeGrossiste();
        $categorie = $this->makeCategorie();
        $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 22000],
        );

        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), [
                'tarifs' => [
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::ENLEVEMENT->value, 'prix' => 15000],
                ],
            ])
            ->assertSessionHasErrors('tarifs');

        $this->assertDatabaseMissing('categorie_tarifs_grossiste', [
            'client_id' => $client->id,
        ]);
    }

    public function test_update_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => $otherOrg->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
        // Catégorie de la propre organisation de l'attaquant (jamais celle de la cible) : c'est
        // le seul scénario réaliste — l'utilisateur ne peut de toute façon pas connaître/soumettre
        // un id valide de l'organisation cible. Isole ainsi le contrôle d'autorisation
        // (ClientPolicy::sameOrganization()) de la validation categorie_id, qui échouerait sinon
        // en amont (302) avant même d'atteindre le contrôleur.
        $categorie = $this->makeCategorie();

        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), [
                'tarifs' => [
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::ENLEVEMENT->value, 'prix' => 18500],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_update_returns_403_without_clients_update_permission(): void
    {
        $this->initOrgAndUser(['clients.read']);
        $client = $this->makeGrossiste();
        $categorie = $this->makeCategorie();

        $this->actingAs($this->user)
            ->put(route('clients.tarifs-grossiste.update', $client), [
                'tarifs' => [
                    ['categorie_id' => $categorie->id, 'mode' => ModeRemiseGrossiste::ENLEVEMENT->value, 'prix' => 18500],
                ],
            ])
            ->assertStatus(403);
    }
}
