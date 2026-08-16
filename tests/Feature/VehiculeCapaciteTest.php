<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
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
 * Portée exclusivement par le TYPE de véhicule (décision produit du 16/08/2026) : un véhicule
 * n'a plus de capacité propre, il hérite entièrement de celle de son type — un seul endroit
 * (page Types de véhicules) pour la régler. Le régime "legacy" (un seul plafond global,
 * type_vehicules.capacite_defaut) est déjà largement couvert par CommandeVenteTest/
 * PdvCheckoutTest — cette classe se concentre sur le nouveau régime et sur la non-régression
 * du repli legacy quand aucune ligne n'est configurée.
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

        // initOrgAndUser() a déjà créé et rattaché un site par défaut (is_default: true) —
        // en recréer un second ici marquerait deux sites "par défaut" pour le même
        // utilisateur, ambigu pour la résolution de site du PDV (wherePivot('is_default', true)
        // ->first()) : on réutilise celui déjà en place.
        $this->defaultSite = Site::where('organization_id', $this->org->id)->firstOrFail();
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

    private function makeTypeVehicule(Organization $org, array $overrides = []): TypeVehicule
    {
        return TypeVehicule::factory()->create(array_merge(['organization_id' => $org->id], $overrides));
    }

    private function makeVehicule(Organization $org, TypeVehicule $typeVehicule): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule_id' => $typeVehicule->id,
        ]);
    }

    /**
     * Utilisateur avec la permission ventes.qte.update — ne doit plus avoir aucun effet sur le
     * contrôle de capacité (décision produit du 15/08/2026, cf. VehiculeCapaciteService).
     */
    private function makeUserAvecQteUpdate(): User
    {
        $user = $this->makeUserWithPermissions($this->org, [
            'ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete',
            'vehicules.read', 'vehicules.create', 'vehicules.update', 'vehicules.delete',
            'ventes.qte.update',
        ]);
        $user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── Vente web — régime "par catégorie" (capacité du type) ────────────────────

    public function test_capacites_par_categorie_sont_independantes_et_cumulables(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

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
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

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
        $typeVehicule = $this->makeTypeVehicule($this->org);
        // Aucune capacité définie pour "Bouteille" — doit rester illimitée.
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

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
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

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
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

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

    // ── Non-régression du régime legacy ──────────────────────────────────────────

    public function test_vehicule_sans_aucune_capacite_par_categorie_reste_sur_le_plafond_global_du_type(): void
    {
        $categorie = $this->makeCategorie($this->org, 'Divers');
        $typeVehicule = $this->makeTypeVehicule($this->org, ['capacite_defaut' => 5]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
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

    // ── Aucun bypass de capacité, quel que soit le rôle ──────────────────────────

    public function test_permission_qte_update_ne_permet_plus_de_depasser_le_regime_legacy(): void
    {
        $categorie = $this->makeCategorie($this->org, 'Divers');
        $typeVehicule = $this->makeTypeVehicule($this->org, ['capacite_defaut' => 5]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit', 'categorie_id' => $categorie->id], ['prix_vente' => 1000]);

        $this->actingAs($this->makeUserAvecQteUpdate())
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 6, 'prix_vente' => 1000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_permission_qte_update_ne_permet_plus_de_depasser_le_regime_par_categorie(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachet->id], ['prix_vente' => 1000]);

        $this->actingAs($this->makeUserAvecQteUpdate())
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 71, 'prix_vente' => 1000],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    // ── PDV ───────────────────────────────────────────────────────────────────────

    public function test_pdv_capacites_par_categorie_sont_independantes(): void
    {
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $bouteille = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteille->id, 'capacite_max' => 100]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

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
        $sachet = $this->makeCategorie($this->org, 'Sachet');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $typeVehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachet->id, 'capacite_max' => 70]);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

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
