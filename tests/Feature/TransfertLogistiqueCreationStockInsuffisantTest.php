<?php

namespace Tests\Feature;

use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Correctif du 04/09/2026 : avant celui-ci, un transfert pouvait être CRÉÉ avec une quantité
 * demandée supérieure au stock du site source — seul le CHARGEMENT ("Valider le chargement") le
 * bloquait ensuite (cf. TransfertLogistiqueService::checkDisponibiliteStockSource()). Le
 * contrôle de disponibilité (TransfertLogistiqueService::verifierDisponibiliteLignes()) est
 * désormais réutilisé aux DEUX moments : création (store()) et modification (update()) — jamais
 * dupliqué en logique, seulement en point d'appel — même pattern que
 * CommandeVenteCreationStockInsuffisantTest côté vente. Contrairement à la vente, AUCUN
 * paramètre n'assouplit jamais ce contrôle pour un transfert (cf. VenteAutoriseeSansStockTest,
 * qui couvre déjà le comportement strict au chargement).
 */
class TransfertLogistiqueCreationStockInsuffisantTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $siteSource;

    private Site $siteDestination;

    private Vehicule $vehicule;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.update', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->assignRole('admin_entreprise');
        $this->user->givePermissionTo(['logistique.create', 'logistique.read', 'logistique.update']);

        $this->siteSource = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Source',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->siteDestination = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Destination',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->siteSource->id, ['role' => 'employe', 'is_default' => true]);

        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);

        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille de 1500ml', 'type' => 'materiel'],
        );
    }

    private function seedStock(int $qte): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->produit->variantePrincipale()->first()->id, 'site_id' => $this->siteSource->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    private function postStore(int $qte)
    {
        return $this->actingAs($this->user)->post('/backoffice/logistique', [
            'site_source_id' => $this->siteSource->id,
            'site_destination_id' => $this->siteDestination->id,
            'vehicule_id' => $this->vehicule->id,
            'lignes' => [
                ['produit_id' => $this->produit->id, 'quantite_demandee' => $qte, 'notes' => ''],
            ],
        ]);
    }

    // ── Création (store) ──────────────────────────────────────────────────────

    public function test_creation_refusee_si_quantite_superieure_au_stock(): void
    {
        $this->seedStock(46);

        $this->postStore(54)->assertSessionHasErrors('lignes');

        $this->assertDatabaseCount('transferts_logistiques', 0);
        // Refus EN ENTIER : le stock reste strictement inchangé.
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $this->siteSource->id,
            'qte_stock' => 46,
        ]);
    }

    public function test_creation_autorisee_si_quantite_egale_au_stock(): void
    {
        $this->seedStock(46);

        $this->postStore(46)->assertRedirect();

        $this->assertDatabaseCount('transferts_logistiques', 1);
    }

    public function test_creation_autorisee_si_quantite_strictement_inferieure_au_stock(): void
    {
        $this->seedStock(46);

        $this->postStore(45)->assertRedirect();

        $this->assertDatabaseCount('transferts_logistiques', 1);
    }

    public function test_creation_refusee_si_stock_nul(): void
    {
        $this->seedStock(0);

        $this->postStore(1)->assertSessionHasErrors('lignes');

        $this->assertDatabaseCount('transferts_logistiques', 0);
    }

    public function test_message_derreur_contient_le_nom_produit_et_les_quantites_exactes(): void
    {
        $this->seedStock(46);

        $response = $this->postStore(54);

        $errors = $response->getSession()->get('errors');
        $this->assertStringContainsString(
            'Stock insuffisant pour « Pack Bouteille de 1500ml » sur le site source : 54 demandés, 46 disponibles.',
            $errors->first('lignes'),
        );
    }

    // ── Modification (update) ─────────────────────────────────────────────────

    private function creerTransfertBrouillon(int $qte): TransfertLogistique
    {
        $variante = $this->produit->variantePrincipale()->first();

        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->siteSource->id,
            'site_destination_id' => $this->siteDestination->id,
            'statut' => StatutTransfert::BROUILLON,
            'created_by' => $this->user->id,
        ]);
        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $variante->id,
            'quantite_demandee' => $qte,
        ]);

        return $transfert;
    }

    private function putUpdate(TransfertLogistique $transfert, int $qte)
    {
        return $this->actingAs($this->user)->put("/backoffice/logistique/{$transfert->id}", [
            'site_destination_id' => $this->siteDestination->id,
            'vehicule_id' => $this->vehicule->id,
            'lignes' => [
                ['produit_id' => $this->produit->id, 'quantite_demandee' => $qte, 'notes' => ''],
            ],
        ]);
    }

    public function test_modification_refusee_si_nouvelle_quantite_superieure_au_stock(): void
    {
        $transfert = $this->creerTransfertBrouillon(5);
        $this->seedStock(46);

        $this->putUpdate($transfert, 54)->assertSessionHasErrors('lignes');

        // Le transfert n'a pas été modifié : la ligne garde son ancienne quantité (5).
        $this->assertDatabaseHas('transfert_lignes', [
            'transfert_logistique_id' => $transfert->id,
            'quantite_demandee' => 5,
        ]);
    }

    public function test_modification_autorisee_si_nouvelle_quantite_dans_le_stock(): void
    {
        $transfert = $this->creerTransfertBrouillon(5);
        $this->seedStock(46);

        $this->putUpdate($transfert, 46)->assertRedirect();

        $this->assertDatabaseHas('transfert_lignes', [
            'transfert_logistique_id' => $transfert->id,
            'quantite_demandee' => 46,
        ]);
    }

    // ── Aucune permissivité, même avec la politique vente sans stock activée ────

    public function test_creation_reste_refusee_avec_stock_insuffisant_meme_si_politique_vente_activee(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->seedStock(46);

        $this->postStore(54)->assertSessionHasErrors('lignes');

        $this->assertDatabaseCount('transferts_logistiques', 0);
    }

    // ── Le contrôle porte sur le site source, jamais un autre site ─────────────

    public function test_le_controle_porte_sur_le_site_source_jamais_un_autre_site(): void
    {
        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Site',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        // Stock confortable sur un AUTRE site, mais 0 sur le site source du transfert.
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
            'site_id' => $autreSite->id,
            'qte_stock' => 1000,
        ]);
        $this->seedStock(0);

        $this->postStore(10)->assertSessionHasErrors('lignes');
        $this->assertDatabaseCount('transferts_logistiques', 0);
    }

    // ── Isolation multi-organisation ────────────────────────────────────────────

    public function test_isolation_entre_organisations(): void
    {
        $orgB = Organization::factory()->create();
        Feature::for($orgB)->activate(ModuleFeature::LOGISTIQUE);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $userB->assignRole('admin_entreprise');
        $userB->givePermissionTo(['logistique.create', 'logistique.read']);

        $siteSourceB = Site::create([
            'organization_id' => $orgB->id,
            'nom' => 'Site Source B',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $siteDestB = Site::create([
            'organization_id' => $orgB->id,
            'nom' => 'Site Destination B',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $userB->sites()->attach($siteSourceB->id, ['role' => 'employe', 'is_default' => true]);

        $vehiculeB = Vehicule::factory()->create([
            'organization_id' => $orgB->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);
        $produitB = $this->makeProduitAvecVariante($orgB, ['nom' => 'Pack B', 'type' => 'materiel']);
        VarianteStock::create([
            'organization_id' => $orgB->id,
            'produit_variante_id' => $produitB->variantePrincipale()->first()->id,
            'site_id' => $siteSourceB->id,
            'qte_stock' => 46,
        ]);

        // Org A : 46 disponibles, transfert de 54 → refusé.
        $this->seedStock(46);
        $this->postStore(54)->assertSessionHasErrors('lignes');

        // Org B : mêmes 46 disponibles mais un produit distinct, transfert de 54 → refusé aussi
        // (chaque organisation applique sa propre règle sur ses propres données), jamais affecté
        // par le refus de l'organisation A.
        $this->actingAs($userB)
            ->post('/backoffice/logistique', [
                'site_source_id' => $siteSourceB->id,
                'site_destination_id' => $siteDestB->id,
                'vehicule_id' => $vehiculeB->id,
                'lignes' => [
                    ['produit_id' => $produitB->id, 'quantite_demandee' => 54, 'notes' => ''],
                ],
            ])
            ->assertSessionHasErrors('lignes');

        $this->assertDatabaseCount('transferts_logistiques', 0);
    }
}
