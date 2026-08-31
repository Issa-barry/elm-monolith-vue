<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommandeVenteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete']);

        // Ce fichier ne teste pas la disponibilité du stock — évite que le nouveau contrôle de
        // CommandeVenteController::store() (23/08/2026, cf. CommandeVenteService::
        // siteAutoriseNouvelleCommande()) ne bloque des commandes de test sans rapport avec le stock.
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        // Attacher un site par défaut pour passer le middleware RequireSiteAssigned
        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private ?Categorie $categorieDefaut = null;

    /**
     * Catégorie partagée par tous les produits/véhicules de ce fichier de tests — la capacité
     * se contrôle par catégorie du catalogue produit (cf. VehiculeCapaciteService), donc un
     * produit sans catégorie n'est jamais concerné par un contrôle, quelle que soit la capacité
     * du véhicule.
     */
    private function defaultCategorie(): Categorie
    {
        return $this->categorieDefaut ??= Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Défaut',
        ]);
    }

    private function makeContext(Organization $org): array
    {
        $produit = $this->makeProduitAvecVariante(
            $org,
            ['nom' => 'Rouleau', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $this->setCapacite($vehicule, 2);

        $client = Client::factory()->create(['organization_id' => $org->id]);

        return compact('produit', 'vehicule', 'client');
    }

    /**
     * Définit la capacité utilisée par les contrôles de ce test — portée exclusivement par le
     * véhicule lui-même via vehicule_capacites (décision produit du 17/08/2026, cf.
     * VehiculeCapaciteService), jamais par son type. updateOrCreate() car makeContext() a déjà
     * posé une ligne à 2 pour cette catégorie — un second appel doit la remplacer, pas entrer
     * en conflit avec la contrainte unique (vehicule_id, categorie_id).
     */
    private function setCapacite(Vehicule $vehicule, int $capacite): void
    {
        $vehicule->capacites()->updateOrCreate(
            ['categorie_id' => $this->defaultCategorie()->id],
            ['organization_id' => $this->org->id, 'capacite_max' => $capacite],
        );
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
                ->where('vehicules.0.capacites.0.capacite_max', 2)
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
            'statut' => 'a_charger',
        ]);
    }

    public function test_store_creates_commande_with_client_and_redirects(): void
    {
        ['produit' => $produit, 'client' => $client] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => (int) $produit->variantePrincipale()->first()->prix_vente],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'statut' => 'facturation',
        ]);
    }

    public function test_store_commande_directe_cree_facture_associee(): void
    {
        ['produit' => $produit, 'client' => $client] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => (int) $produit->variantePrincipale()->first()->prix_vente],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertNotNull($commande);
        $this->assertEquals(StatutCommandeVente::FACTURATION, $commande->statut);

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_net' => (int) $produit->variantePrincipale()->first()->prix_vente,
        ]);
    }

    public function test_store_commande_logistique_cree_facture_en_statut_creee(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();
        $this->assertNotNull($commande);
        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->statut);

        // La facture existe dès la création de la commande, mais pas encore encaissable.
        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'statut_facture' => 'creee',
        ]);
    }

    public function test_cloture_automatique_commande_directe_sur_paiement_complet(): void
    {
        // Non éligible aux commissions : une commande directe sans véhicule ne peut de
        // toute façon jamais générer de commission équipe_livraison (cf.
        // cloturerSiComplete(), qui ne clôture plus silencieusement une commande
        // éligible en échec de génération — incident CMD-230826-004).
        ['client' => $client] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::FACTURATION,
            'total_commande' => 3000,
            'commission_eligible_snapshot' => false,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 3000,
            'montant_net' => 3000,
        ]);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 3000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::CLOTUREE, $commande->fresh()->statut);
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

    public function test_store_fails_when_total_quantite_exceeds_vehicule_capacity(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 5);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 6, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_succeeds_when_total_quantite_below_vehicule_capacity(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 10);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 3, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_fails_when_multi_lines_total_exceed_vehicule_capacity(): void
    {
        ['vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 5);

        $p1 = $this->makeProduitAvecVariante($this->org, ['nom' => 'Px', 'categorie_id' => $this->defaultCategorie()->id], ['prix_vente' => 1000, 'prix_usine' => 800]);
        $p2 = $this->makeProduitAvecVariante($this->org, ['nom' => 'Py', 'categorie_id' => $this->defaultCategorie()->id], ['prix_vente' => 1500, 'prix_usine' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $p1->id, 'qte' => 3, 'prix_vente' => 1000],
                    ['produit_id' => $p2->id, 'qte' => 4, 'prix_vente' => 1500],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_store_ignore_la_capacite_par_defaut_du_type_vehicule_sans_capacite_est_illimite(): void
    {
        // Décision produit du 17/08/2026 : TypeVehicule::capacite_defaut est une colonne morte,
        // jamais lue — un véhicule sans ligne vehicule_capacites n'est simplement pas limité,
        // quelle que soit la valeur historique portée par son type.
        ['produit' => $produit] = $this->makeContext($this->org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $typeVehicule = TypeVehicule::factory()->create([
            'organization_id' => $this->org->id,
            'capacite_defaut' => 5,
        ]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule_id' => $typeVehicule->id,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 999, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    // ── qte éditable / capacité véhicule par défaut ───────────────────────────

    public function test_store_accepts_qte_equal_to_vehicule_capacity(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        // Capacité du véhicule = 2, cf. makeContext()

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_accepts_multiple_lignes_summing_to_capacity(): void
    {
        ['vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 10);

        $produit1 = $this->makeProduitAvecVariante($this->org, ['nom' => 'P1', 'categorie_id' => $this->defaultCategorie()->id], ['prix_vente' => 1000, 'prix_usine' => 800]);
        $produit2 = $this->makeProduitAvecVariante($this->org, ['nom' => 'P2', 'categorie_id' => $this->defaultCategorie()->id], ['prix_vente' => 1500, 'prix_usine' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit1->id, 'qte' => 6, 'prix_vente' => 1000],
                    ['produit_id' => $produit2->id, 'qte' => 4, 'prix_vente' => 1500],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_fails_with_zero_qte(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 0, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('lignes.0.qte');
    }

    public function test_store_with_client_only_accepts_any_valid_qte(): void
    {
        // Sans véhicule (fallback qte=1), tous les rôles autorisés peuvent saisir
        ['produit' => $produit, 'client' => $client] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['client_id' => $client->id]);
    }

    // ── store : paramètre autoriser_saisie_dessous_qte_max ───────────────────

    public function test_create_exposes_autoriser_saisie_dessous_qte_max_prop(): void
    {
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $this->actingAs($this->user)
            ->get(route('ventes.create'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Create')
                ->where('autoriser_saisie_dessous_qte_max', false)
            );
    }

    public function test_store_fails_when_below_capacity_and_chargement_complet_required(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 10);
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 3, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_succeeds_when_exactly_at_capacity_with_chargement_complet_required(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 3);
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 3, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_below_capacity_still_allowed_when_param_enabled(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 10);
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, true);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 4, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    // ── update : capacité véhicule ────────────────────────────────────────────

    public function test_update_accepts_qte_within_capacity(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 10);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 2000,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['id' => $commande->id, 'vehicule_id' => $vehicule->id]);
    }

    public function test_update_fails_when_total_quantite_exceeds_vehicule_capacity(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 5);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 2000,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 6, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_update_fails_when_multi_lines_total_exceed_vehicule_capacity(): void
    {
        ['vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 5);

        $p1 = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pa', 'categorie_id' => $this->defaultCategorie()->id], ['prix_vente' => 1000, 'prix_usine' => 800]);
        $p2 = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pb', 'categorie_id' => $this->defaultCategorie()->id], ['prix_vente' => 1500, 'prix_usine' => 1000]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 5000,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $p1->id, 'qte' => 3, 'prix_vente' => 1000],
                    ['produit_id' => $p2->id, 'qte' => 4, 'prix_vente' => 1500],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_update_ignore_la_capacite_par_defaut_du_type_vehicule_sans_capacite_est_illimite(): void
    {
        // Décision produit du 17/08/2026 : TypeVehicule::capacite_defaut est une colonne morte,
        // jamais lue — un véhicule sans ligne vehicule_capacites n'est simplement pas limité.
        ['produit' => $produit] = $this->makeContext($this->org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $typeVehicule = TypeVehicule::factory()->create([
            'organization_id' => $this->org->id,
            'capacite_defaut' => 5,
        ]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule_id' => $typeVehicule->id,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 2000,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 999, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionDoesntHaveErrors();
    }

    // ── update : paramètre autoriser_saisie_dessous_qte_max ──────────────────

    public function test_update_fails_when_below_capacity_and_chargement_complet_required(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 10);
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 2000,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_update_succeeds_when_exactly_at_capacity_with_chargement_complet_required(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 5);
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 2000,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();
    }

    public function test_update_with_client_only_accepts_any_valid_qte(): void
    {
        ['produit' => $produit, 'client' => $client] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 2000,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 999, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['id' => $commande->id, 'client_id' => $client->id]);
    }

    public function test_create_exposes_vehicule_capacity_for_new_ligne_default(): void
    {
        ['vehicule' => $vehicule] = $this->makeContext($this->org);
        $this->setCapacite($vehicule, 50);

        $this->actingAs($this->user)
            ->get(route('ventes.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Create')
                ->has('vehicules', fn (Assert $v) => $v
                    ->where('0.capacites.0.capacite_max', 50)
                    ->etc()
                )
            );
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
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => (float) $produit->variantePrincipale()->first()->prix_usine,
            'prix_vente_snapshot' => (float) $produit->variantePrincipale()->first()->prix_vente,
            'total_ligne' => 2 * (float) $produit->variantePrincipale()->first()->prix_vente,
        ]);

        $this->actingAs($this->user)
            ->get(route('ventes.edit', $commande))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Edit')
                ->where('vehicules.0.id', $vehicule->id)
                ->where('vehicules.0.capacites.0.capacite_max', 2)
            );
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_store_fails_when_prix_unitaire_is_changed_without_permission(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => (int) $produit->variantePrincipale()->first()->prix_vente + 500],
                ],
            ])
            ->assertSessionHasErrors('lignes.0.prix_vente');
    }

    public function test_store_accepts_custom_prix_unitaire_with_permission(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.prix.update', 'guard_name' => 'web']);
        $this->user->givePermissionTo('ventes.prix.update');

        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $customPrice = (int) $produit->variantePrincipale()->first()->prix_vente + 500;

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => $customPrice],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::query()->latest('id')->first();

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'prix_vente_snapshot' => $customPrice,
        ]);
    }

    public function test_update_fails_when_prix_unitaire_is_changed_without_permission(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => (float) $produit->variantePrincipale()->first()->prix_vente,
        ]);

        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 1,
            'prix_usine_snapshot' => (float) $produit->variantePrincipale()->first()->prix_usine,
            'prix_vente_snapshot' => (float) $produit->variantePrincipale()->first()->prix_vente,
            'total_ligne' => (float) $produit->variantePrincipale()->first()->prix_vente,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => (int) $produit->variantePrincipale()->first()->prix_vente + 500],
                ],
            ])
            ->assertSessionHasErrors('lignes.0.prix_vente');
    }

    public function test_update_accepts_existing_custom_prix_without_permission(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $customPrice = (int) $produit->variantePrincipale()->first()->prix_vente + 700;

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => $customPrice,
        ]);

        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 1,
            'prix_usine_snapshot' => (float) $produit->variantePrincipale()->first()->prix_usine,
            'prix_vente_snapshot' => (float) $customPrice,
            'total_ligne' => (float) $customPrice,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => $customPrice],
                ],
            ])
            ->assertRedirect();
    }

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

    // ── valider : BROUILLON → A_CHARGER ──────────────────────────────────────

    public function test_valider_transitions_brouillon_to_a_charger(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 5000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => (float) $produit->variantePrincipale()->first()->prix_usine,
            'prix_vente_snapshot' => (float) $produit->variantePrincipale()->first()->prix_vente,
            'total_ligne' => 2 * (float) $produit->variantePrincipale()->first()->prix_vente,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.valider', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);
    }

    public function test_valider_cree_la_facture_en_statut_creee(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => (float) $produit->variantePrincipale()->first()->prix_usine,
            'prix_vente_snapshot' => (float) $produit->variantePrincipale()->first()->prix_vente,
            'total_ligne' => 2 * (float) $produit->variantePrincipale()->first()->prix_vente,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.valider', $commande))
            ->assertRedirect();

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'statut_facture' => 'creee',
        ]);
    }

    // ── annuler : BROUILLON|A_CHARGER → ANNULEE ──────────────────────────────

    public function test_annuler_sets_statut_annulee(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_fails_without_motif(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation_code');
    }

    public function test_annuler_fails_with_invalid_code(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => 'code_invalide',
            ])
            ->assertSessionHasErrors('motif_annulation_code');
    }

    public function test_annuler_fails_with_autre_without_detail(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => 'autre',
            ])
            ->assertSessionHasErrors('motif_annulation_detail');
    }

    public function test_annuler_succeeds_with_autre_and_stores_detail(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => 'autre',
                'motif_annulation_detail' => 'Raison spécifique',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'id' => $commande->id,
            'motif_annulation' => 'Autre: Raison spécifique',
        ]);
    }

    #[DataProvider('motifStandardProvider')]
    public function test_annuler_stores_correct_label_for_standard_motif(string $code, string $expectedLabel): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => $code,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'id' => $commande->id,
            'motif_annulation' => $expectedLabel,
        ]);
    }

    public static function motifStandardProvider(): array
    {
        return [
            'erreur_saisie' => ['erreur_saisie', 'Erreur de saisie'],
            'doublon' => ['doublon', 'Doublon'],
            'rupture_stock' => ['rupture_stock', 'Rupture de stock'],
        ];
    }

    public function test_annuler_returns_403_si_encaissement_existe(): void
    {
        ['client' => $client] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::FACTURATION,
            'total_commande' => 5000,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 5000,
            'montant_net' => 5000,
        ]);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertStatus(422);

        $this->assertNotEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_returns_403_if_already_annulee(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::ANNULEE,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => 'doublon',
            ])
            ->assertStatus(403);
    }

    public function test_annuler_returns_403_from_chargement_en_cours(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertStatus(403);
    }

    // ── auto-clôture : LIVRAISON_EN_COURS → LIVREE → CLOTUREE ────────────────

    public function test_auto_cloture_when_facture_fully_paid_and_no_commissions(): void
    {
        // Non éligible aux commissions (aucun véhicule lié) : ce test porte sur la
        // clôture elle-même quand il n'y a explicitement rien à générer — jamais un
        // scénario où la génération échouerait (cf. cloturerSiComplete(), qui ne
        // clôture plus silencieusement une commande éligible en échec de génération,
        // incident CMD-230826-004).
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 5000,
            'commission_eligible_snapshot' => false,
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

    // ── référence PREFIXE-JJMMAA-XXX (VTE/DST/TRF, révisé le 31/08/2026) ─────

    /**
     * Remplace l'ancien test_store_genere_reference_au_format_cmd : décision produit du
     * 31/08/2026, le préfixe dépend désormais de nature_operation — vente_standard reçoit
     * VTE- (jamais CMD-, réservé aux références déjà émises avant ce chantier).
     */
    public function test_store_genere_reference_au_format_vte_pour_vente_standard(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest()->first();

        $this->assertNotNull($commande);
        $this->assertMatchesRegularExpression('/^VTE-\d{6}-\d{3}$/', $commande->reference);
    }

    /**
     * distribution_client (client DISTRIBUTEUR + véhicule) reçoit DST- — jamais VTE-, jamais le
     * même compteur que la vente standard (séquences indépendantes par préfixe, cf.
     * ReferenceNumeroService).
     */
    public function test_store_genere_reference_au_format_dst_pour_distribution_client(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);
        $distributeur = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::DISTRIBUTEUR->value,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'client_id' => $distributeur->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest()->first();

        $this->assertNotNull($commande);
        $this->assertSame('distribution_client', $commande->nature_operation->value);
        $this->assertMatchesRegularExpression('/^DST-\d{6}-\d{3}$/', $commande->reference);
    }

    /**
     * Le compteur est désormais journalier et scopé par organisation + préfixe (cf.
     * ReferenceNumeroService) — remplace l'ancien test_references_incrementales_dans_le_mois,
     * dont le nom supposait à tort un compteur mensuel.
     */
    public function test_references_incrementales_dans_la_meme_journee_pour_le_meme_prefixe(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule, 'client' => $client] = $this->makeContext($this->org);

        // Deuxième commande sur le client (vente directe), pas sur le même véhicule : depuis le
        // verrou « première régularisation » (cf. SolvabiliteService), la première commande
        // laisse une facture non encaissée sur ce véhicule qui bloquerait une deuxième commande
        // véhicule immédiate — hors sujet ici, ce test ne vise que la numérotation séquentielle.
        $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000]],
        ]);
        $this->actingAs($this->user)->post(route('ventes.store'), [
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000]],
        ]);

        $commandes = CommandeVente::where('organization_id', $this->org->id)
            ->orderBy('numero')
            ->get();

        $this->assertStringEndsWith('-001', $commandes->first()->reference);
        $this->assertStringEndsWith('-002', $commandes->last()->reference);
        $this->assertStringStartsWith('VTE-', $commandes->first()->reference);
        $this->assertStringStartsWith('VTE-', $commandes->last()->reference);
    }

    public function test_valider_sets_a_charger_at_timestamp(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => (float) $produit->variantePrincipale()->first()->prix_usine,
            'prix_vente_snapshot' => (float) $produit->variantePrincipale()->first()->prix_vente,
            'total_ligne' => 2 * (float) $produit->variantePrincipale()->first()->prix_vente,
        ]);

        $this->actingAs($this->user)
            ->patch(route('ventes.valider', $commande))
            ->assertRedirect();

        $this->assertNotNull($commande->fresh()->a_charger_at);
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
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->delete(route('ventes.destroy', $commande))
            ->assertStatus(403);
    }
}
