<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
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
 * Correctif du 24/08/2026 : avant celui-ci, une commande pouvait être CRÉÉE avec une quantité
 * supérieure au stock — seul le CHARGEMENT la bloquait ensuite. Le contrôle de disponibilité
 * (CommandeVenteService::verifierDisponibiliteLignes()) est désormais réutilisé aux TROIS
 * moments : création (store()), modification (update()) et chargement — jamais dupliqué en
 * logique, seulement en point d'appel. Quand le paramètre global autorise la vente sans stock,
 * les trois restent permissifs ; les transferts et ajustements manuels restent, eux, toujours
 * stricts quel que soit ce paramètre (couvert par VenteAutoriseeSansStockTest).
 */
class CommandeVenteCreationStockInsuffisantTest extends TestCase
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

    private function postStore(int $qte)
    {
        return $this->actingAs($this->user)->post('/backoffice/ventes', [
            'client_id' => $this->client->id,
            'lignes' => [
                ['produit_id' => $this->produit->id, 'qte' => $qte, 'prix_vente' => 20000],
            ],
        ]);
    }

    // ── Création (store) — paramètre OFF (défaut) ────────────────────────────

    public function test_creation_refusee_si_quantite_superieure_au_stock(): void
    {
        $this->seedStock(460);

        $this->postStore(540)->assertSessionHasErrors('lignes');

        $this->assertDatabaseCount('commandes_ventes', 0);
        // Refus EN ENTIER : le stock reste strictement inchangé (aucun mouvement, même partiel).
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 460,
        ]);
    }

    public function test_creation_autorisee_si_quantite_egale_au_stock(): void
    {
        $this->seedStock(460);

        $this->postStore(460)->assertRedirect();

        $this->assertDatabaseCount('commandes_ventes', 1);
    }

    public function test_creation_autorisee_si_quantite_strictement_inferieure_au_stock(): void
    {
        $this->seedStock(460);

        $this->postStore(459)->assertRedirect();

        $this->assertDatabaseCount('commandes_ventes', 1);
    }

    public function test_creation_refusee_si_stock_nul(): void
    {
        // Témoin avec du stock : sans lui, le site n'aurait aucun stock vendable du tout et
        // create()/store() seraient bloqués par le garde-fou plus grossier de la page (cf.
        // CommandeVenteCreationBloqueeTest) plutôt que par LE contrôle testé ici, ligne par
        // ligne, sur CE produit précis.
        $temoin = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Témoin', 'type' => 'fabricable'],
            ['prix_vente' => 5000, 'prix_usine' => 4000],
        );
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $temoin->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 5,
        ]);

        $this->seedStock(0);

        $this->postStore(1)->assertSessionHasErrors('lignes');

        $this->assertDatabaseCount('commandes_ventes', 0);
    }

    public function test_message_derreur_contient_le_nom_produit_et_les_quantites_exactes(): void
    {
        $this->seedStock(460);

        $response = $this->postStore(540);

        $errors = $response->getSession()->get('errors');
        $this->assertStringContainsString(
            'Stock insuffisant pour « Pack Bouteille de 1500ml » : 540 demandés, 460 disponibles.',
            $errors->first('lignes'),
        );
    }

    // ── Modification (update) — paramètre OFF ────────────────────────────────

    /**
     * Construit directement une commande en statut BROUILLON. Impossible de passer par
     * store() pour ça : CommandeVenteController::store() fait toujours avancer une commande
     * fraîchement créée hors BROUILLON dans la même requête (confirmer() si vehicule_id,
     * sinon creerFactureDirecte()) — jamais de commande qui reste en BROUILLON à l'issue d'un
     * POST réel. Une commande n'est modifiable via update() (abort_if(!isBrouillon())) que si
     * elle a été construite directement ainsi, comme le fait déjà CommandeVenteModeTarificationTest.
     */
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

    public function test_modification_refusee_si_nouvelle_quantite_superieure_au_stock(): void
    {
        $commande = $this->creerCommandeBrouillon(50);
        $this->seedStock(460);

        $this->actingAs($this->user)
            ->put("/backoffice/ventes/{$commande->id}", [
                'client_id' => $this->client->id,
                'lignes' => [
                    ['produit_id' => $this->produit->id, 'qte' => 540, 'prix_vente' => 20000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        // La commande n'a pas été modifiée : la ligne garde son ancienne quantité (50).
        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'quantite_demandee' => 50,
        ]);
    }

    public function test_modification_autorisee_si_nouvelle_quantite_dans_le_stock(): void
    {
        $commande = $this->creerCommandeBrouillon(50);
        $this->seedStock(460);

        $this->actingAs($this->user)
            ->put("/backoffice/ventes/{$commande->id}", [
                'client_id' => $this->client->id,
                'lignes' => [
                    ['produit_id' => $this->produit->id, 'qte' => 460, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'quantite_demandee' => 460,
        ]);
    }

    // ── Paramètre ON : création et modification restent autorisées ──────────

    public function test_creation_autorisee_avec_stock_insuffisant_quand_politique_on(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(460);

        $this->postStore(540)->assertRedirect();

        $this->assertDatabaseCount('commandes_ventes', 1);
    }

    public function test_creation_autorisee_avec_stock_nul_quand_politique_on(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(0);

        $this->postStore(50)->assertRedirect();

        $this->assertDatabaseCount('commandes_ventes', 1);
    }

    public function test_modification_autorisee_avec_stock_insuffisant_quand_politique_on(): void
    {
        $commande = $this->creerCommandeBrouillon(50);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(10);

        $this->actingAs($this->user)
            ->put("/backoffice/ventes/{$commande->id}", [
                'client_id' => $this->client->id,
                'lignes' => [
                    ['produit_id' => $this->produit->id, 'qte' => 999, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'quantite_demandee' => 999,
        ]);
    }

    // ── Le stock peut changer entre création et confirmation : recontrôlé ────

    /**
     * Correctif du 24/08/2026 (StockReservationService) : la commande RÉSERVE sa quantité dès
     * la confirmation (BROUILLON → A_CHARGER), plus seulement au chargement — le stock a pu
     * changer entre la création du brouillon et sa confirmation (autre commande confirmée
     * entre-temps), donc la confirmation le recontrôle elle aussi. Avant ce correctif, ce même
     * scénario n'échouait qu'au CHARGEMENT (validerChargement()) — trop tard : une seconde
     * commande concurrente aurait déjà pu être confirmée entre-temps en promettant le même
     * stock.
     */
    public function test_la_confirmation_recontrole_le_stock_meme_apres_une_creation_validee(): void
    {
        // Créée avec 100 <= 1000 disponibles à cet instant (cf. creerCommandeBrouillon()) : autorisée.
        $commande = $this->creerCommandeBrouillon(100);

        // Une autre vente (ou ajustement) fait chuter le stock du site à 30 AVANT la confirmation.
        $this->seedStock(30);

        $this->actingAs($this->user);

        try {
            CommandeVenteService::confirmer($commande);
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu : le stock a changé depuis la création, la confirmation le revérifie.
        }

        $this->assertEquals(StatutCommandeVente::BROUILLON, $commande->fresh()->statut);
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 30,
            'qte_reservee' => 0,
        ]);
    }

    /**
     * La réservation créée à la confirmation couvre la quantité DEMANDÉE — un écart positif au
     * chargement (plus chargé que réservé) reste soumis à un contrôle de disponibilité pour le
     * surplus, jamais couvert automatiquement par la réservation existante.
     */
    public function test_le_chargement_recontrole_le_stock_pour_un_ecart_positif_non_couvert_par_la_reservation(): void
    {
        $commande = $this->creerCommandeBrouillon(100);
        $ligne = $commande->lignes()->first();
        $this->seedStock(100);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);

        // 100 sont réservés (et physiquement disponibles) ; aucun stock supplémentaire n'est
        // arrivé — un chargement de 150 (surplus) doit donc être refusé.
        try {
            CommandeVenteService::validerChargement($commande, [[
                'id' => $ligne->id,
                'quantite_chargee' => 150,
                'type_ecart' => 'surplus',
            ]]);
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu
        }

        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 100,
            'qte_reservee' => 100,
        ]);
    }

    // ── Le contrôle porte sur la variante ET le site, jamais le produit global ──

    public function test_le_controle_porte_sur_le_site_courant_jamais_un_autre_site(): void
    {
        // Témoin sur le site courant (cf. commentaire de test_creation_refusee_si_stock_nul) :
        // isole le contrôle par-ligne testé ici du garde-fou plus grossier "site sans aucun
        // stock vendable".
        $temoin = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Témoin', 'type' => 'fabricable'],
            ['prix_vente' => 5000, 'prix_usine' => 4000],
        );
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $temoin->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 5,
        ]);

        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Site',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        // Stock confortable sur un AUTRE site, mais 0 sur le site courant de l'utilisateur.
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $autreSite->id,
            'qte_stock' => 1000,
        ]);
        $this->seedStock(0);

        $this->postStore(10)->assertSessionHasErrors('lignes');
        $this->assertDatabaseCount('commandes_ventes', 0);
    }

    public function test_le_controle_porte_sur_la_variante_concernee_jamais_une_autre(): void
    {
        $autreVariante = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille de 500ml', 'type' => 'fabricable'],
            ['prix_vente' => 8000, 'prix_usine' => 6000],
        );
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $autreVariante->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 1000,
        ]);
        // Le produit réellement commandé ($this->produit) reste à 0 sur ce même site.
        $this->seedStock(0);

        $this->postStore(10)->assertSessionHasErrors('lignes');
        $this->assertDatabaseCount('commandes_ventes', 0);
    }

    // ── Isolation multi-organisation ─────────────────────────────────────────

    public function test_isolation_entre_organisations(): void
    {
        $orgB = Organization::factory()->create();
        $userB = $this->makeUserWithPermissions($orgB, ['ventes.read', 'ventes.create', 'ventes.update']);
        $siteB = Site::create([
            'organization_id' => $orgB->id,
            'nom' => 'Site B',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $userB->sites()->attach($siteB->id, ['role' => 'employe', 'is_default' => true]);
        $produitB = $this->makeProduitAvecVariante(
            $orgB,
            ['nom' => 'Pack B', 'type' => 'fabricable'],
            ['prix_vente' => 20000, 'prix_usine' => 15000],
        );
        $clientB = Client::factory()->create(['organization_id' => $orgB->id, 'type' => 'externe']);
        VarianteStock::create([
            'organization_id' => $orgB->id,
            'produit_variante_id' => $produitB->variantePrincipale()->first()->id,
            'site_id' => $siteB->id,
            'qte_stock' => 460,
        ]);

        // Org A : 460 disponibles, commande de 540 → refusée.
        $this->seedStock(460);
        $this->postStore(540)->assertSessionHasErrors('lignes');

        // Org B : mêmes 460 disponibles mais un produit distinct, commande de 540 → refusée
        // aussi (chaque organisation applique sa propre règle sur ses propres données), jamais
        // affectée par la commande refusée de l'organisation A.
        $this->actingAs($userB)
            ->post('/backoffice/ventes', [
                'client_id' => $clientB->id,
                'lignes' => [
                    ['produit_id' => $produitB->id, 'qte' => 540, 'prix_vente' => 20000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseCount('commandes_ventes', 0);
    }
}
