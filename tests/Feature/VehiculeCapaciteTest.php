<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
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
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Capacité maximale de chargement par catégorie de produit (Sachet eau / Bouteille...) —
 * décision produit du 17/08/2026 : portée EXCLUSIVEMENT par le véhicule lui-même, aucun
 * héritage depuis le type (classification pure). La Categorie du catalogue produit EST
 * directement la référence de capacité — il n'existe plus de notion intermédiaire de "groupe
 * de capacité" (revirement du 17/08/2026 : le modèle GroupeCapacite a été retiré au profit
 * d'une réutilisation directe de Categorie). Voir VehiculeCapaciteService.
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
            'logistique.read', 'logistique.create', 'logistique.update',
        ]);

        // initOrgAndUser() a déjà créé et rattaché un site par défaut (is_default: true) —
        // en recréer un second ici marquerait deux sites "par défaut" pour le même
        // utilisateur, ambigu pour la résolution de site du PDV (wherePivot('is_default', true)
        // ->first()) : on réutilise celui déjà en place.
        $this->defaultSite = Site::where('organization_id', $this->org->id)->firstOrFail();

        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);
    }

    private function makeCategorie(Organization $org, string $nom): Categorie
    {
        return Categorie::create(['organization_id' => $org->id, 'nom' => $nom]);
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

    private function makeTypeVehicule(Organization $org): TypeVehicule
    {
        return TypeVehicule::factory()->create(['organization_id' => $org->id]);
    }

    private function makeVehicule(Organization $org, TypeVehicule $typeVehicule, bool $logistique = false): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule_id' => $typeVehicule->id,
            'livraison_vente' => ! $logistique,
            'livraison_logistique' => $logistique,
        ]);
    }

    private function makeSiteDestination(Organization $org): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Destination',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    /**
     * Utilisateur avec la permission ventes.qte.update — ne doit avoir aucun effet sur le
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

    // ── Vente web — capacités par catégorie ────────────────────────────────────────

    public function test_capacites_par_categorie_sont_independantes_et_cumulables(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteilles->id], ['prix_vente' => 3000]);

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
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteilles->id], ['prix_vente' => 3000]);

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
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        // Aucune capacité définie pour "Bouteille" — doit rester illimité.
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);

        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteilles->id], ['prix_vente' => 3000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitBouteille->id, 'qte' => 500, 'prix_vente' => 3000],
                ],
            ])
            ->assertRedirect();
    }

    public function test_vehicule_sans_aucune_capacite_nest_pas_limite(): void
    {
        $categorie = $this->makeCategorie($this->org, 'Divers');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit', 'categorie_id' => $categorie->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100000, 'prix_vente' => 1000],
                ],
            ])
            ->assertRedirect();
    }

    /**
     * Un produit sans catégorie n'est concerné par aucun contrôle — l'absence de configuration
     * n'équivaut jamais à une capacité nulle (décision explicite, cf. VehiculeCapaciteService).
     */
    public function test_produit_sans_categorie_nest_jamais_controle_meme_si_le_vehicule_est_plafonne(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 10]);

        $produitSansCategorie = $this->makeProduitAvecVariante($this->org, ['nom' => 'Divers', 'categorie_id' => null], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSansCategorie->id, 'qte' => 999, 'prix_vente' => 1000],
                ],
            ])
            ->assertRedirect();
    }

    public function test_chargement_complet_obligatoire_sapplique_par_categorie(): void
    {
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);

        // Vend uniquement des sachets, pas de bouteilles du tout : la catégorie "Bouteille"
        // n'étant pas dans la commande, elle n'est pas soumise à l'obligation de chargement
        // complet — seule la catégorie effectivement vendue doit atteindre son plafond.
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

        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'qte' => 50, 'prix_vente' => 1000],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    // ── Aucun bypass de capacité, quel que soit le rôle ──────────────────────────

    public function test_permission_qte_update_ne_permet_plus_de_depasser_la_capacite(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);

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
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'categorie_id' => $bouteilles->id], ['prix_vente' => 3000]);
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
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);

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

    /**
     * Deux produits DIFFÉRENTS de la même catégorie doivent voir leurs quantités cumulées avant
     * comparaison au plafond — jamais contrôlées indépendamment ligne par ligne (même règle que
     * la vente web, cf. CommandeVenteTest::test_store_fails_when_multi_lines_total_exceed_vehicule_capacity).
     */
    public function test_pdv_plusieurs_produits_de_la_meme_categorie_sont_cumules(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);

        $produitA = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 350ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitB = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);
        $this->makeStock($produitA, 200);
        $this->makeStock($produitB, 200);

        // 40 + 30 = 70 (exactement le plafond) : autorisé.
        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitA->id, 'quantite' => 40],
                    ['produit_id' => $produitB->id, 'quantite' => 30],
                ],
            ])
            ->assertSessionDoesntHaveErrors('lignes');

        // 40 + 31 = 71 > 70 : refusé, alors qu'aucune ligne prise isolément ne dépasse le plafond.
        $this->actingAs($this->user)
            ->post('/backoffice/pdv/checkout', [
                'mode' => 'Livreur',
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitA->id, 'quantite' => 40],
                    ['produit_id' => $produitB->id, 'quantite' => 31],
                ],
            ])
            ->assertSessionHasErrors('lignes');
    }

    // ── Saisie des capacités : formulaire véhicule (création/modification) ──────

    public function test_store_vehicule_avec_capacites_les_enregistre_atomiquement(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Nafaya',
                'immatriculation' => 'RC6985',
                'type_vehicule_id' => $typeVehicule->id,
                'site_id' => $this->defaultSite->id,
                'proprietaire_id' => $proprietaire->id,
                'categorie' => 'partenaire',
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'capacites' => [
                    ['categorie_id' => $sachets->id, 'capacite_max' => 1700],
                    ['categorie_id' => $bouteilles->id, 'capacite_max' => 3400],
                ],
            ]);

        $response->assertRedirect();
        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC6985')->firstOrFail();
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $sachets->id, 'capacite_max' => 1700]);
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 3400]);
    }

    public function test_update_vehicule_remplace_integralement_les_capacites(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 50]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $typeVehicule->id,
                'site_id' => $this->defaultSite->id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => $vehicule->categorie->value,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'capacites' => [
                    ['categorie_id' => $bouteilles->id, 'capacite_max' => 120],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $sachets->id]);
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 120]);
    }

    public function test_update_refuse_deux_lignes_pour_la_meme_categorie(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $typeVehicule->id,
                'site_id' => $this->defaultSite->id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => $vehicule->categorie->value,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'capacites' => [
                    ['categorie_id' => $sachets->id, 'capacite_max' => 50],
                    ['categorie_id' => $sachets->id, 'capacite_max' => 80],
                ],
            ])
            ->assertSessionHasErrors('capacites.0.categorie_id');
    }

    #[DataProvider('capaciteInvalideProvider')]
    public function test_update_refuse_une_capacite_invalide(int $capacite): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $typeVehicule->id,
                'site_id' => $this->defaultSite->id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => $vehicule->categorie->value,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'capacites' => [
                    ['categorie_id' => $sachets->id, 'capacite_max' => $capacite],
                ],
            ])
            ->assertSessionHasErrors('capacites.0.capacite_max');
    }

    public static function capaciteInvalideProvider(): array
    {
        return [
            'zéro' => [0],
            'négative' => [-5],
        ];
    }

    /**
     * Round-trip complet : une capacité enregistrée via le formulaire de modification doit
     * être immédiatement visible sur la fiche détail du véhicule (jamais "non plafonné" juste
     * après un enregistrement réussi) — bout en bout store→show, pas seulement la persistance
     * en base déjà couverte par le test ci-dessus.
     */
    public function test_capacite_enregistree_est_immediatement_visible_sur_la_fiche_detail(): void
    {
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $typeVehicule->id,
                'site_id' => $this->defaultSite->id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => $vehicule->categorie->value,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'capacites' => [
                    ['categorie_id' => $bouteilles->id, 'capacite_max' => 100],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicule.capacites.0.categorie_nom', 'Bouteille')
                ->where('vehicule.capacites.0.capacite_max', 100)
            );

        // La page Modifier doit elle-même re-préremplir la ligne existante (pas de régression
        // au second aller-retour édition, cf. VehiculeController::edit()).
        $this->actingAs($this->user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->where('capacites.0.categorie_id', $bouteilles->id)
                ->where('capacites.0.capacite_max', 100)
            );
    }

    public function test_suppression_dune_capacite_disparait_de_la_fiche_detail(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($this->org, 'Bouteille');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 50]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 80]);

        // Remplace intégralement par une seule ligne (Sachet eau) — Bouteille doit disparaître.
        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $typeVehicule->id,
                'site_id' => $this->defaultSite->id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => $vehicule->categorie->value,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'capacites' => [
                    ['categorie_id' => $sachets->id, 'capacite_max' => 50],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vehicule.capacites', 1)
                ->where('vehicule.capacites.0.categorie_nom', 'Sachet eau')
            );
    }

    // ── Logistique — parité avec la vente, sans exigence de chargement complet ──

    public function test_logistique_depassement_dune_categorie_est_rejete(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule, logistique: true);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $siteDestination = $this->makeSiteDestination($this->org);
        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);

        $response = $this->actingAs($this->user)
            ->post(route('logistique.store'), [
                'site_source_id' => $this->defaultSite->id,
                'site_destination_id' => $siteDestination->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'quantite_demandee' => 71, 'notes' => ''],
                ],
            ]);

        $response->assertSessionHasErrors('lignes');
        $this->assertDatabaseMissing('transferts_logistiques', ['vehicule_id' => $vehicule->id]);
    }

    public function test_logistique_chargement_partiel_est_autorise_sans_exigence_de_complet(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule, logistique: true);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $siteDestination = $this->makeSiteDestination($this->org);
        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);

        // Bien en dessous du plafond (70) — la logistique n'exige jamais un chargement complet,
        // contrairement à la vente (pas de paramètre équivalent).
        $response = $this->actingAs($this->user)
            ->post(route('logistique.store'), [
                'site_source_id' => $this->defaultSite->id,
                'site_destination_id' => $siteDestination->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitSachet->id, 'quantite_demandee' => 5, 'notes' => ''],
                ],
            ]);

        $response->assertSessionDoesntHaveErrors('lignes');
        $this->assertDatabaseHas('transferts_logistiques', ['vehicule_id' => $vehicule->id]);
    }

    /**
     * Même règle de cumul par catégorie que la vente/le PDV, appliquée aux transferts
     * logistiques : deux produits différents de la même catégorie voient leurs quantités
     * additionnées avant comparaison au plafond.
     */
    public function test_logistique_plusieurs_produits_de_la_meme_categorie_sont_cumules(): void
    {
        $sachets = $this->makeCategorie($this->org, 'Sachet eau');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule, logistique: true);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'categorie_id' => $sachets->id, 'capacite_max' => 70]);
        $siteDestination = $this->makeSiteDestination($this->org);
        $produitA = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 350ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitB = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'categorie_id' => $sachets->id], ['prix_vente' => 1000]);

        // 40 + 31 = 71 > 70 : refusé, alors qu'aucune ligne prise isolément ne dépasse le plafond.
        $response = $this->actingAs($this->user)
            ->post(route('logistique.store'), [
                'site_source_id' => $this->defaultSite->id,
                'site_destination_id' => $siteDestination->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produitA->id, 'quantite_demandee' => 40, 'notes' => ''],
                    ['produit_id' => $produitB->id, 'quantite_demandee' => 31, 'notes' => ''],
                ],
            ]);

        $response->assertSessionHasErrors('lignes');
        $this->assertDatabaseMissing('transferts_logistiques', ['vehicule_id' => $vehicule->id]);
    }
}
