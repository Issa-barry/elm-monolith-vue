<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Models\Categorie;
use App\Models\CategorieTarifGrossiste;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Révision produit du 05/09/2026 (deuxième décision le même jour, après la mise en place du
 * tarif par client) :
 *  1. Le mode de remise Grossiste (Enlèvement/Livraison) n'est plus un choix utilisateur
 *     indépendant — dérivé uniquement de la présence d'un véhicule (deriverModeRemiseGrossiste()).
 *  2. Le tarif Grossiste spécial est une SURCHARGE facultative du prix normal, jamais une
 *     obligation — absence de tarif = prix normal du produit, jamais un blocage.
 * Cf. docs/grossiste.md.
 */
class CommandeVenteGrossisteModeEtFallbackTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private User $user;

    private Organization $org;

    private Site $site;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create', 'ventes.update']);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille d\'eau',
            'statut' => 'actif',
        ]);
    }

    private function makeGrossiste(): Client
    {
        return Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
    }

    private function makeProduit(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $this->categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 20000],
        );
    }

    /** Sans équipe : ensurePartageLivraisonCategorieConfigure() se désactive elle-même. */
    private function makeVehicule(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'is_active' => true,
            'livraison_vente' => true,
        ]);
    }

    // ── 1/2. Mode dérivé du véhicule ─────────────────────────────────────────────

    public function test_grossiste_sans_vehicule_mode_enlevement_automatique(): void
    {
        $produit = $this->makeProduit();
        $client = $this->makeGrossiste();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertSame('enlevement', $commande->mode_remise_grossiste->value);
        $this->assertNull($commande->vehicule_id);
    }

    public function test_grossiste_avec_vehicule_mode_livraison_automatique(): void
    {
        $produit = $this->makeProduit();
        $client = $this->makeGrossiste();
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertSame('livraison', $commande->mode_remise_grossiste->value);
        $this->assertSame($vehicule->id, $commande->vehicule_id);
    }

    public function test_soumettre_un_mode_remise_grossiste_dans_la_requete_est_sans_effet(): void
    {
        // Le champ n'est plus lu du tout côté serveur — une requête forgée qui tenterait de
        // soumettre LIVRAISON sans véhicule reste dérivée en ENLEVEMENT.
        $produit = $this->makeProduit();
        $client = $this->makeGrossiste();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'mode_remise_grossiste' => 'livraison',
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertSame('enlevement', $commande->mode_remise_grossiste->value);
    }

    // ── 3/4/5. Tarif spécial en surcharge facultative ────────────────────────────

    public function test_tarif_special_configure_est_applique(): void
    {
        $produit = $this->makeProduit();
        $client = $this->makeGrossiste();
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'categorie_id' => $this->categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 18200,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(36400.0, (float) $commande->total_commande); // 2 × 18200
        $this->assertSame('grossiste', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_tarif_special_absent_retombe_sur_le_prix_normal_du_produit(): void
    {
        $produit = $this->makeProduit(); // prix_vente = 20000, aucun tarif Grossiste configuré
        $client = $this->makeGrossiste();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertEquals(40000.0, (float) $commande->total_commande); // 2 × 20000 (prix normal)
        $this->assertSame('vente', $commande->lignes->first()->prix_origine_snapshot->value);
    }

    public function test_grossiste_k2_sans_tarif_ne_recupere_jamais_le_tarif_de_k1(): void
    {
        $produit = $this->makeProduit();
        $k1 = $this->makeGrossiste();
        $k2 = $this->makeGrossiste();
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $k1->id,
            'categorie_id' => $this->categorie->id,
            'mode' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'prix' => 18500,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $k2->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $k2->id)->latest()->first();
        $this->assertEquals(20000.0, (float) $commande->total_commande); // prix normal, jamais 18500
    }

    public function test_tarif_special_livraison_applique_avec_vehicule(): void
    {
        $produit = $this->makeProduit();
        $client = $this->makeGrossiste();
        $vehicule = $this->makeVehicule();
        CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'categorie_id' => $this->categorie->id,
            'mode' => ModeRemiseGrossiste::LIVRAISON->value,
            'prix' => 18500,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 20000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();
        $this->assertSame('livraison', $commande->mode_remise_grossiste->value);
        $this->assertEquals(37000.0, (float) $commande->total_commande); // 2 × 18500
    }
}
