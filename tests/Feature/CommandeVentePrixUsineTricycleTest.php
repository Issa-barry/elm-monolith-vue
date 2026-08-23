<?php

namespace Tests\Feature;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Le prix usine appliqué (et snapshoté sur la ligne) dépend de la catégorie tarifaire du type
 * du véhicule de flotte livrant la commande — cf. TypeVehicule::categorie_tarifaire,
 * PrixUsineResolver, VehiculeCommandeContextResolver. Indépendant du montant réellement
 * facturé au client (toujours prix_vente pour un véhicule de flotte, cf.
 * CommandeVenteModeTarificationTest) : le prix usine snapshoté sert de base de marge pour la
 * commission (CommissionCalculator), pas de montant facturé, dans ce contexte.
 */
class CommandeVentePrixUsineTricycleTest extends TestCase
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

    private function makeProduit(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Eau'],
            ['prix_vente' => 6000, 'prix_usine' => 5100, 'prix_usine_tricycle' => 5050],
        );
    }

    private function makeVehicule(?CategorieTarifaireVehicule $categorieTarifaire): Vehicule
    {
        $type = TypeVehicule::factory()->create([
            'organization_id' => $this->org->id,
            'categorie_tarifaire' => $categorieTarifaire,
        ]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $type->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
    }

    public function test_commande_avec_vehicule_tricycle_snapshote_le_tarif_tricycle(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(CategorieTarifaireVehicule::TRICYCLE);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 6000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();

        // Facturation toujours au prix de vente plein (véhicule de flotte, comportement
        // historique inchangé) — seul le prix usine SNAPSHOTÉ varie avec la catégorie.
        $this->assertEquals(60_000.0, (float) $commande->total_commande);
        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'prix_usine_snapshot' => 5050,
        ]);
    }

    public function test_commande_avec_vehicule_autre_categorie_snapshote_le_tarif_standard(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(CategorieTarifaireVehicule::AUTRE_VEHICULE);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 6000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'prix_usine_snapshot' => 5100,
        ]);
    }

    public function test_commande_avec_type_vehicule_non_classe_retombe_sur_le_tarif_standard(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(null);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 6000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'prix_usine_snapshot' => 5100,
        ]);
    }

    public function test_deux_commandes_meme_variante_vehicules_differents_snapshotent_des_tarifs_differents(): void
    {
        $produit = $this->makeProduit();
        $tricycle = $this->makeVehicule(CategorieTarifaireVehicule::TRICYCLE);
        $camion = $this->makeVehicule(CategorieTarifaireVehicule::AUTRE_VEHICULE);

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $tricycle->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 6000]],
        ])->assertRedirect();

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $camion->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 6000]],
        ])->assertRedirect();

        $commandeTricycle = CommandeVente::where('vehicule_id', $tricycle->id)->latest()->first();
        $commandeCamion = CommandeVente::where('vehicule_id', $camion->id)->latest()->first();

        $this->assertEquals(5050.0, (float) $commandeTricycle->lignes()->first()->prix_usine_snapshot);
        $this->assertEquals(5100.0, (float) $commandeCamion->lignes()->first()->prix_usine_snapshot);
    }

    public function test_snapshot_reste_fige_si_le_tarif_tricycle_du_produit_change_ensuite(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(CategorieTarifaireVehicule::TRICYCLE);

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 6000]],
        ])->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();
        $this->assertEquals(5050.0, (float) $commande->lignes()->first()->prix_usine_snapshot);

        // Le tarif tricycle du produit évolue après coup.
        $produit->variantePrincipale()->first()->update(['prix_usine_tricycle' => 5200]);

        $commande->refresh();
        $this->assertEquals(5050.0, (float) $commande->lignes()->first()->prix_usine_snapshot, 'Le snapshot ne doit jamais être recalculé rétroactivement.');
    }

    /**
     * Décision métier : aucun repli implicite de prix_usine_tricycle vers prix_usine — une
     * variante historique (créée hors ProduitService, donc jamais validée) sans tarif tricycle
     * dédié doit bloquer la commande avec une erreur explicite plutôt que d'appliquer
     * silencieusement le tarif "autres véhicules" à un tricycle.
     */
    public function test_commande_avec_vehicule_tricycle_refuse_si_le_tarif_tricycle_nest_pas_renseigne(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Sans Tricycle'],
            ['prix_vente' => 6000, 'prix_usine' => 5100, 'prix_usine_tricycle' => null],
        );
        $vehicule = $this->makeVehicule(CategorieTarifaireVehicule::TRICYCLE);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 6000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }
}
