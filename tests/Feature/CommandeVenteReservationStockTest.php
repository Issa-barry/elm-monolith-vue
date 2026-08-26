<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutReservationStock;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Correctif du 24/08/2026 (StockReservationService) : « Disponible » cesse de refléter le seul
 * stock physique — une commande RÉSERVE sa quantité dès sa confirmation (BROUILLON → A_CHARGER),
 * plus seulement au chargement. Avant ce correctif, deux commandes concurrentes pouvaient toutes
 * deux être confirmées (« À charger ») en promettant le même stock physique : le conflit n'était
 * détecté qu'au chargement de l'une des deux, l'autre restant bloquée indéfiniment sans qu'aucun
 * signal ne soit visible avant ce moment-là.
 */
class CommandeVenteReservationStockTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create', 'ventes.update']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille de 1500ml', 'type' => 'fabricable'],
            ['prix_vente' => 20000, 'prix_usine' => 15000],
        );
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);
    }

    private function seedStock(int $qte): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->produit->variantePrincipale()->first()->id, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    private function creerCommandeBrouillon(int $qte): CommandeVente
    {
        $variante = $this->produit->variantePrincipale()->first();
        $totalLigne = $qte * 20000.0;

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $this->client->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => $totalLigne,
        ]);
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => 15000.0,
            'prix_vente_snapshot' => 20000.0,
            'total_ligne' => $totalLigne,
        ]);

        return $commande;
    }

    private function varianteId(): string
    {
        return $this->produit->variantePrincipale()->first()->id;
    }

    // ── Confirmer réserve ─────────────────────────────────────────────────────

    public function test_confirmer_reserve_la_quantite_demandee(): void
    {
        $this->seedStock(100);
        $commande = $this->creerCommandeBrouillon(40);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'qte_stock' => 100,
            'qte_reservee' => 40,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'quantite' => 40,
            'statut' => StatutReservationStock::ACTIVE->value,
        ]);
    }

    /**
     * Le scénario exact du bug rapporté : une commande à 2 000 packs disponibles réserve 540 —
     * une seconde commande qui tente de réserver plus que ce qui reste (1 460) doit être refusée
     * DÈS LA CONFIRMATION, jamais laissée passer jusqu'au chargement.
     */
    public function test_deux_commandes_concurrentes_ne_peuvent_pas_reserver_plus_que_le_disponible(): void
    {
        $this->seedStock(2000);
        $commandeA = $this->creerCommandeBrouillon(540);
        $commandeB = $this->creerCommandeBrouillon(1600);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commandeA);

        try {
            CommandeVenteService::confirmer($commandeB);
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu : 2000 - 540 = 1460 disponibles, 1600 demandés.
        }

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commandeA->fresh()->statut);
        $this->assertEquals(StatutCommandeVente::BROUILLON, $commandeB->fresh()->statut);
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'qte_stock' => 2000,
            'qte_reservee' => 540,
        ]);
    }

    // ── Annuler libère ────────────────────────────────────────────────────────

    public function test_annuler_depuis_a_charger_libere_la_reservation(): void
    {
        $this->seedStock(100);
        $commande = $this->creerCommandeBrouillon(40);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::annuler($commande, 'erreur_saisie');

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'qte_stock' => 100,
            'qte_reservee' => 0,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'statut' => StatutReservationStock::LIBEREE->value,
        ]);
    }

    // ── Chargement validé consomme ────────────────────────────────────────────

    /**
     * Écart négatif (moins chargé que réservé) : le surplus non chargé redevient
     * automatiquement disponible, jamais bloqué indéfiniment par une réservation fantôme.
     */
    public function test_chargement_valide_consomme_la_reservation_et_libere_le_surplus_non_charge(): void
    {
        $this->seedStock(100);
        $commande = $this->creerCommandeBrouillon(50);
        $ligne = $commande->lignes()->first();

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => 30,
            'type_ecart' => 'manquant',
        ]]);

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'qte_stock' => 70,
            'qte_reservee' => 0,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'statut' => StatutReservationStock::CONSOMMEE->value,
        ]);
    }

    // ── Le PDV respecte les réservations vente (protection croisée) ──────────

    public function test_le_pdv_ne_peut_pas_vendre_un_stock_deja_reserve_par_une_commande_vente(): void
    {
        $this->seedStock(50);
        $commande = $this->creerCommandeBrouillon(50);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);

        // 50 physique - 50 réservés = 0 disponible : le PDV ne doit plus pouvoir vendre.
        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
        ])->assertSessionHasErrors('lignes');

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'qte_stock' => 50,
            'qte_reservee' => 50,
        ]);
    }
}
