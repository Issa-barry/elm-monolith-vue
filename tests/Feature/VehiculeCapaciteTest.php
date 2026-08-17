<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\GroupeCapacite;
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
use Laravel\Pennant\Feature;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Capacité maximale de chargement par groupe de capacité (Sachets / Bouteilles...) — décision
 * produit du 17/08/2026 : portée EXCLUSIVEMENT par le véhicule lui-même, aucun héritage depuis
 * le type (classification pure). GroupeCapacite est délibérément distinct de la Categorie du
 * catalogue produit — voir VehiculeCapaciteService et le modèle GroupeCapacite.
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

    private function makeGroupe(Organization $org, string $nom): GroupeCapacite
    {
        return GroupeCapacite::create(['organization_id' => $org->id, 'nom' => $nom]);
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

    // ── Vente web — capacités par groupe ──────────────────────────────────────────

    public function test_capacites_par_groupe_sont_independantes_et_cumulables(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $bouteilles = $this->makeGroupe($this->org, 'Bouteilles');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'groupe_capacite_id' => $bouteilles->id], ['prix_vente' => 3000]);

        // Chargé au maximum des deux groupes simultanément : autorisé (cumulable).
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

    public function test_depassement_dun_seul_groupe_est_rejete_sans_affecter_lautre(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $bouteilles = $this->makeGroupe($this->org, 'Bouteilles');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'groupe_capacite_id' => $bouteilles->id], ['prix_vente' => 3000]);

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

    public function test_groupe_sans_ligne_de_capacite_nest_pas_limite(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $bouteilles = $this->makeGroupe($this->org, 'Bouteilles');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        // Aucune capacité définie pour "Bouteilles" — doit rester illimité.
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);

        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'groupe_capacite_id' => $bouteilles->id], ['prix_vente' => 3000]);

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
        $groupe = $this->makeGroupe($this->org, 'Divers');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit', 'groupe_capacite_id' => $groupe->id], ['prix_vente' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100000, 'prix_vente' => 1000],
                ],
            ])
            ->assertRedirect();
    }

    public function test_chargement_complet_obligatoire_sapplique_par_groupe(): void
    {
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $bouteilles = $this->makeGroupe($this->org, 'Bouteilles');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);

        // Vend uniquement des sachets, pas de bouteilles du tout : le groupe "Bouteilles"
        // n'étant pas dans la commande, il n'est pas soumis à l'obligation de chargement
        // complet — seul le groupe effectivement vendu doit atteindre son plafond.
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

    public function test_chargement_incomplet_dun_groupe_vendu_est_rejete(): void
    {
        Parametre::setVentesAutorisationSaisieDessousQteMax($this->org->id, false);

        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);

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
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);
        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);

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

    public function test_pdv_capacites_par_groupe_sont_independantes(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $bouteilles = $this->makeGroupe($this->org, 'Bouteilles');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 100]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);
        $produitBouteille = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1.5L', 'groupe_capacite_id' => $bouteilles->id], ['prix_vente' => 3000]);
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

    public function test_pdv_depassement_dun_groupe_est_rejete(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);

        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);

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

    // ── Saisie des capacités : formulaire véhicule (création/modification) ──────

    public function test_store_vehicule_avec_capacites_les_enregistre_atomiquement(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $bouteilles = $this->makeGroupe($this->org, 'Bouteilles');
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
                    ['groupe_capacite_id' => $sachets->id, 'capacite_max' => 1700],
                    ['groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 3400],
                ],
            ]);

        $response->assertRedirect();
        $vehicule = Vehicule::where('organization_id', $this->org->id)->where('immatriculation', 'RC6985')->firstOrFail();
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 1700]);
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 3400]);
    }

    public function test_update_vehicule_remplace_integralement_les_capacites(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $bouteilles = $this->makeGroupe($this->org, 'Bouteilles');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 50]);

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
                    ['groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 120],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'groupe_capacite_id' => $sachets->id]);
        $this->assertDatabaseHas('vehicule_capacites', ['vehicule_id' => $vehicule->id, 'groupe_capacite_id' => $bouteilles->id, 'capacite_max' => 120]);
    }

    // ── Logistique — parité avec la vente, sans exigence de chargement complet ──

    public function test_logistique_depassement_dun_groupe_est_rejete(): void
    {
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule, logistique: true);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);
        $siteDestination = $this->makeSiteDestination($this->org);
        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);

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
        $sachets = $this->makeGroupe($this->org, 'Sachets');
        $typeVehicule = $this->makeTypeVehicule($this->org);
        $vehicule = $this->makeVehicule($this->org, $typeVehicule, logistique: true);
        $vehicule->capacites()->create(['organization_id' => $this->org->id, 'groupe_capacite_id' => $sachets->id, 'capacite_max' => 70]);
        $siteDestination = $this->makeSiteDestination($this->org);
        $produitSachet = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml', 'groupe_capacite_id' => $sachets->id], ['prix_vente' => 1000]);

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
}
