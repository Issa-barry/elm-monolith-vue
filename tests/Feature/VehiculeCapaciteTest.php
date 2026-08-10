<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Capacité par catégorie de produit (sachets / bouteilles...) — décision produit du
 * 10/08/2026 : cumulable (plafonds indépendants par catégorie), voir VehiculeCapaciteService.
 * Le régime "legacy" (un seul plafond global, capacite_packs) est déjà largement couvert par
 * CommandeVenteTest/PdvCheckoutTest — cette classe se concentre sur le nouveau régime et sur
 * la non-régression du repli legacy quand aucune ligne n'est configurée.
 */
class VehiculeCapaciteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete',
            'vehicules.read', 'vehicules.create', 'vehicules.update', 'vehicules.delete',
            'type-vehicules.read', 'type-vehicules.create', 'type-vehicules.update', 'type-vehicules.delete',
        ]);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeCategorie(Organization $org, string $nom): Categorie
    {
        return Categorie::create(['organization_id' => $org->id, 'nom' => $nom, 'statut' => 'actif']);
    }

    private function makeStock(Produit $produit, int $qte): void
    {
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->defaultSite->id,
            'qte_stock' => $qte,
        ]);
    }

    private function makeVehicule(Organization $org, array $overrides = []): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        return Vehicule::factory()->create(array_merge([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ], $overrides));
    }

    // ── Vente web — régime "par catégorie" ───────────────────────────────────────

    public function test_capacites_par_categorie_sont_independantes_et_cumulables(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteille->id], ['prix_vente' => 3000]);

        // Chargé au maximum des deux catégories simultanément : autorisé (cumulable).
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 70, 'prix_vente' => 1000],
                    ['produit_id' => $produitBouteille->id, 'qte' => 100, 'prix_vente' => 3000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_depassement_dune_seule_categorie_est_rejete_sans_affecter_lautre(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteille->id], ['prix_vente' => 3000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 71, 'prix_vente' => 1000],
                    ['produit_id' => $produitBouteille->id, 'qte' => 50, 'prix_vente' => 3000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_categorie_sans_ligne_de_capacite_nest_pas_limitee(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null]);
        // Aucune capacité définie pour "Bouteille" — doit rester illimitée.
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);

        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteille->id], ['prix_vente' => 3000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitBouteille->id, 'qte' => 500, 'prix_vente' => 3000],
                ],
            ])
            ->assertRedirect();
    }

    public function test_chargement_complet_obligatoire_sapplique_par_categorie(): void
    {
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);

        // Vend uniquement des sachets, pas de bouteilles du tout : la bouteille n'étant pas
        // dans la commande, elle n'est pas soumise à l'obligation de chargement complet —
        // seule la catégorie effectivement vendue doit atteindre son plafond.
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 70, 'prix_vente' => 1000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_chargement_incomplet_dune_categorie_vendue_est_rejete(): void
    {
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 50, 'prix_vente' => 1000],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_capacite_type_vehicule_sert_de_repli_par_categorie(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 20]);
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null, 'type_vehicule_id' => $typeVehicule->id]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 21, 'prix_vente' => 1000],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    public function test_vehicule_capacite_prevaut_sur_le_repli_type(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 20]);
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null, 'type_vehicule_id' => $typeVehicule->id]);
        // Override propre au véhicule : doit primer sur celle du type.
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 90]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 80, 'prix_vente' => 1000],
                ],
            ])
            ->assertRedirect();
    }

    // ── Non-régression du régime legacy ──────────────────────────────────────────

    public function test_vehicule_sans_aucune_ligne_de_capacite_reste_sur_le_plafond_global(): void
    {
        $categorie = $this->makeCategorie($this->org, 'Divers');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => 5]);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit', 'categorie_id' => $categorie->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 6, 'prix_vente' => 1000],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    // ── PDV ───────────────────────────────────────────────────────────────────────

    public function test_pdv_capacites_par_categorie_sont_independantes(): void
    {
        $this->initOrgAndUser(['produits.read']);
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteille->id], ['prix_vente' => 3000]);
        $this->makeStock($produitSachet, 200);
        $this->makeStock($produitBouteille, 200);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'quantite' => 70],
                    ['produit_id' => $produitBouteille->id, 'quantite' => 100],
                ],
            ])
            ->assertSessionDoesntHaveErrors('lignes');
    }

    public function test_pdv_depassement_dune_categorie_est_rejete(): void
    {
        $this->initOrgAndUser(['produits.read']);
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $vehicule = $this->makeVehicule($this->org, ['capacite_packs' => null]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'quantite' => 71],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    // ── Synchronisation des capacités (véhicule) ─────────────────────────────────

    public function test_sync_capacites_vehicule_cree_les_lignes(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put("/backoffice/vehicules/{$vehicule->id}/capacites", [
                'capacites' => [
                    ['categorie_id' => $sachet->id, 'capacite_max' => 70],
                    ['categorie_id' => $bouteille->id, 'capacite_max' => 100],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);
        $this->assertSame(2, $vehicule->capacites()->count());
    }

    public function test_sync_capacites_vehicule_remplace_les_lignes_existantes(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 50]);

        $this->actingAs($this->user)
            ->put("/backoffice/vehicules/{$vehicule->id}/capacites", [
                'capacites' => [
                    ['categorie_id' => $bouteille->id, 'capacite_max' => 100],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(1, $vehicule->capacites()->count());
        $this->assertDatabaseMissing('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $sachet->id]);
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $bouteille->id]);
    }

    public function test_sync_capacites_vehicule_rejette_categorie_dupliquee(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put("/backoffice/vehicules/{$vehicule->id}/capacites", [
                'capacites' => [
                    ['categorie_id' => $sachet->id, 'capacite_max' => 70],
                    ['categorie_id' => $sachet->id, 'capacite_max' => 90],
                ],
            ])
            ->assertSessionHasErrors();
    }

    public function test_sync_capacites_vehicule_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($autreOrg);
        $categorie = $this->makeCategorie($autreOrg, 'Sachet');

        $this->actingAs($this->user)
            ->put("/backoffice/vehicules/{$vehicule->id}/capacites", [
                'capacites' => [['categorie_id' => $categorie->id, 'capacite_max' => 70]],
            ])
            ->assertStatus(403);
    }

    // ── Synchronisation des capacités (type de véhicule) ─────────────────────────

    public function test_sync_capacites_type_vehicule_cree_les_lignes(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put("/backoffice/type-vehicules/{$typeVehicule->id}/capacites", [
                'capacites' => [['categorie_id' => $sachet->id, 'capacite_max' => 20]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('type_vehicule_capacites', ['type_vehicule_id' => $typeVehicule->id, 'categorie_id' => $sachet->id, 'capacite_max' => 20]);
    }
}
