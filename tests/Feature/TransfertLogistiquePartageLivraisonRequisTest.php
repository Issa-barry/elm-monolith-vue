<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Garde-fou préventif à la création d'un transfert logistique — symétrique à
 * CommandeVentePartageLivraisonRequisTest, cf. TransfertLogistiqueController::
 * ensurePartageLivraisonCategorieConfigure(). S'applique à toute organisation depuis le
 * 03/09/2026 (moteur générique devenu le seul moteur, retrait de la bascule par organisation
 * estMigreVersMoteurGenerique() et de l'ancien CommissionLogistiqueService).
 */
class TransfertLogistiquePartageLivraisonRequisTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $siteSource;

    private Site $siteDestination;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read', 'guard_name' => 'web']);

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

        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->assignRole('admin_entreprise');
        $this->user->givePermissionTo(['logistique.create', 'logistique.read']);
        $this->user->sites()->attach($this->siteSource->id, ['role' => 'employe', 'is_default' => true]);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille eau',
        ]);
    }

    private function makeProduit(?Categorie $categorie = null, string $nom = 'Pack Eau'): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => $nom, 'categorie_id' => ($categorie ?? $this->categorie)->id],
        );
    }

    private function makeVehiculeAvecEquipe(): Vehicule
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);

        EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);

        return $vehicule->fresh();
    }

    /** Provisionne/active le processus logistique_transfert de l'organisation (auto-provisionné à
     *  la volée par le moteur générique de toute façon — explicite ici pour les tests). */
    private function activerMoteurGenerique(): CommissionProcessus
    {
        return CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
            'libelle' => 'Transfert logistique',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function creerRegleEquipeLivraison(CommissionProcessus $processus, int $montant): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function definirPartage(Vehicule $vehicule, Categorie $categorie, int $montantUnitaire = 200): void
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        EquipeLivreur::create([
            'equipe_id' => $vehicule->equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $vehicule->equipe->id,
            'categorie_id' => $categorie->id,
            'processus_id' => $processus->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => $montantUnitaire,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function postLogistique(array $payload)
    {
        return $this->actingAs($this->user)->post('/backoffice/logistique', $payload);
    }

    /**
     * Depuis le correctif du 04/09/2026 (contrôle de disponibilité déplacé à la création), tout
     * produit gere_stock=true (cas par défaut de makeProduit()) doit disposer d'un stock
     * suffisant sur le site source pour que la création aboutisse — sans rapport avec le garde-fou
     * de partage commission testé ici.
     */
    private function seedStock(Produit $produit, int $qte = 100): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $produit->variantePrincipale()->first()->id, 'site_id' => $this->siteSource->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    private function basePayload(Vehicule $vehicule, Produit $produit, int $qte = 10): array
    {
        return [
            'site_source_id' => $this->siteSource->id,
            'site_destination_id' => $this->siteDestination->id,
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'quantite_demandee' => $qte, 'notes' => ''],
            ],
        ];
    }

    public function test_store_bloque_si_partage_manquant_pour_une_organisation_migree(): void
    {
        $this->activerMoteurGenerique();
        $vehicule = $this->makeVehiculeAvecEquipe();
        $processus = CommissionProcessus::where('organization_id', $this->org->id)
            ->where('code', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)->firstOrFail();
        $this->creerRegleEquipeLivraison($processus, 200);
        $produit = $this->makeProduit();

        $this->postLogistique($this->basePayload($vehicule, $produit))
            ->assertSessionHasErrors('vehicule_id');

        $this->assertDatabaseMissing('transferts_logistiques', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_autorise_si_partage_configure_pour_une_organisation_migree(): void
    {
        $this->activerMoteurGenerique();
        $vehicule = $this->makeVehiculeAvecEquipe();
        $processus = CommissionProcessus::where('organization_id', $this->org->id)
            ->where('code', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)->firstOrFail();
        $this->creerRegleEquipeLivraison($processus, 200);
        $this->definirPartage($vehicule, $this->categorie);
        $produit = $this->makeProduit();
        $this->seedStock($produit);

        $this->postLogistique($this->basePayload($vehicule, $produit))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('transferts_logistiques', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_autorise_si_aucun_bareme_equipe_configure(): void
    {
        $this->activerMoteurGenerique();
        $vehicule = $this->makeVehiculeAvecEquipe();
        // Aucune CommissionRegle equipe_livraison créée : enveloppe résolue = 0, rien à exiger.
        $produit = $this->makeProduit();
        $this->seedStock($produit);

        $this->postLogistique($this->basePayload($vehicule, $produit))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('transferts_logistiques', ['vehicule_id' => $vehicule->id]);
    }

    public function test_message_erreur_utilise_le_nom_metier_jamais_lidentifiant(): void
    {
        $this->activerMoteurGenerique();
        $vehicule = $this->makeVehiculeAvecEquipe();
        $processus = CommissionProcessus::where('organization_id', $this->org->id)
            ->where('code', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)->firstOrFail();
        $this->creerRegleEquipeLivraison($processus, 200);
        $produit = $this->makeProduit();

        $this->postLogistique($this->basePayload($vehicule, $produit));

        $message = session('errors')->get('vehicule_id')[0];
        $this->assertStringContainsString('Bouteille eau', $message);
        $this->assertStringNotContainsString($this->categorie->id, $message);
    }
}
