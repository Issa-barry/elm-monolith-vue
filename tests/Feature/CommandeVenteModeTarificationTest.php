<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Le montant à encaisser par l'usine varie selon le contexte de la commande :
 *  - un véhicule de flotte gérée (tout véhicule présent dans `vehicules`) : toujours
 *    total = qte × prix_vente ;
 *  - sans véhicule, pour un client EXTERNE : total = qte × prix_usine ;
 *  - sans véhicule, pour un client standard/cashback (ou aucun client) : total = qte × prix_vente
 *    (comportement historique).
 *
 * Cette notion est indépendante de l'éligibilité aux commissions (Vehicule::livraison_vente) —
 * voir CommandeVenteCommissionEligibiliteTest.
 */
class CommandeVenteModeTarificationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        // Ce fichier ne teste pas la disponibilité du stock — évite que le nouveau contrôle de
        // CommandeVenteController::store() (23/08/2026, cf. CommandeVenteService::
        // siteAutoriseNouvelleCommande()) ne bloque des commandes de test sans rapport avec le stock.
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeProduit(int $prixVente = 5000, int $prixUsine = 3500): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Eau'],
            ['prix_vente' => $prixVente, 'prix_usine' => $prixUsine],
        );
    }

    private function makeVehicule(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
    }

    // ── store : total selon le contexte (véhicule / client partenaire) ──────

    public function test_store_avec_vehicule_utilise_toujours_prix_vente(): void
    {
        // Un véhicule de flotte gérée facture toujours au prix de vente plein, que son
        // livraison_vente effectif soit ou non ce qui a permis de le sélectionner.
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();

        $this->assertNotNull($commande);
        $this->assertEquals(500_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_vente', $commande->mode_tarification_snapshot->value);

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'total_ligne' => 500_000,
        ]);
    }

    public function test_store_direct_client_standard_sale_uses_prix_vente(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'standard']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();

        $this->assertEquals(50_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_vente', $commande->mode_tarification_snapshot->value);
    }

    public function test_store_client_externe_sale_uses_prix_usine(): void
    {
        // Un client externe (hors flotte gérée, sans véhicule renseigné) achète à prix usine —
        // la marge lui reste, cf. VehiculeCommandeContextResolver.
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();

        $this->assertEquals(35_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_usine', $commande->mode_tarification_snapshot->value);
        // Aucun véhicule de flotte impliqué : jamais de commission.
        $this->assertFalse((bool) $commande->commission_eligible_snapshot);
    }

    // ── validerChargement : recalcul sur quantité réellement chargée ─────────

    public function test_valider_chargement_recalcule_le_total_au_prix_usine_sur_quantite_chargee(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => null,
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 350_000,
            'mode_tarification_snapshot' => 'prix_usine',
        ]);

        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 100,
            'prix_usine_snapshot' => 3500.0,
            'prix_vente_snapshot' => 5000.0,
            'total_ligne' => 350_000.0,
        ]);

        $this->seedVarianteStockSuffisant($produit->variantePrincipale()->first(), $this->defaultSite);

        // En production, ces transitions n'ont jamais lieu hors d'une requête
        // authentifiée (created_by du mouvement de stock en dépend) — on
        // s'assure donc ici d'un utilisateur courant, comme le ferait le
        // vrai workflow HTTP.
        $this->actingAs($this->user);

        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);

        // Écart au chargement : seulement 90 packs réellement chargés (casse).
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => 90,
            'type_ecart' => 'casse',
        ]]);

        $commande->refresh();

        $this->assertEquals(315_000.0, (float) $commande->total_commande);
        $this->assertEquals(315_000.0, (float) $commande->lignes()->first()->total_ligne);
    }

    // ── update (brouillon) : le mode suit le contexte au moment de l'édition ──

    public function test_update_recalcule_le_mode_quand_on_passe_dun_vehicule_a_un_client_externe(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $vehicule = $this->makeVehicule();
        $clientExterne = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 500_000,
            'mode_tarification_snapshot' => 'prix_vente',
        ]);
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 100,
            'prix_usine_snapshot' => 3500.0,
            'prix_vente_snapshot' => 5000.0,
            'total_ligne' => 500_000.0,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => null,
                'client_id' => $clientExterne->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande->refresh();

        $this->assertEquals(350_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_usine', $commande->mode_tarification_snapshot->value);
    }
}
