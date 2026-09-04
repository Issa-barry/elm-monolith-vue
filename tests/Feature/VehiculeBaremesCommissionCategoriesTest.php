<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * VehiculeController::baremesCommissionParCategorie() — la fiche véhicule et
 * la popup équipe (V2) ne doivent proposer/afficher une catégorie que si AU
 * MOINS UN de ses deux barèmes (Propriétaire ou Livraison) est strictement
 * positif — les deux cibles sont résolues indépendamment, jamais un montant
 * global blended. Régression couverte : une catégorie avec Propriétaire = 500
 * GNF configuré au niveau catégorie mais aucune règle GLOBALE Propriétaire ne
 * doit plus afficher "0 GNF" (cf. incident Bouteille d'eau).
 */
class VehiculeBaremesCommissionCategoriesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read']);
    }

    private function makeVehicule(array $overrides = []): Vehicule
    {
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
        ]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'proprietaire_id' => $proprietaire->id,
            'site_id' => $site->id,
            ...$overrides,
        ]);
    }

    private function activerV2(): CommissionProcessus
    {
        return CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function creerRegle(CommissionProcessus $processus, string $cibleType, float $montant, ?string $categorieId = null): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => $cibleType === CommissionCibleType::CODE_PROPRIETAIRE ? 'Propriétaire' : 'Livraison',
            'scope_type' => $categorieId ? CommissionScopeType::CATEGORIE->value : CommissionScopeType::GLOBAL->value,
            'scope_id' => $categorieId,
            'cible_type' => $cibleType,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    /** @test */
    public function exclut_une_categorie_dont_les_deux_baremes_valent_zero(): void
    {
        $processus = $this->activerV2();
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle($processus, CommissionCibleType::CODE_PROPRIETAIRE, 0, $sachets->id);
        $this->creerRegle($processus, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 0, $sachets->id);

        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('baremes_commission_categories', 0)
            );
    }

    /** @test */
    public function inclut_une_categorie_dont_seul_le_bareme_proprietaire_est_positif(): void
    {
        $processus = $this->activerV2();
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle($processus, CommissionCibleType::CODE_PROPRIETAIRE, 500, $sachets->id);
        $this->creerRegle($processus, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 0, $sachets->id);

        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('baremes_commission_categories', 1)
                ->where('baremes_commission_categories.0.categorie_id', $sachets->id)
                ->where('baremes_commission_categories.0.montant_proprietaire', fn ($val) => (float) $val === 500.0)
                ->where('baremes_commission_categories.0.montant_livraison', fn ($val) => (float) $val === 0.0)
            );
    }

    /** @test */
    public function inclut_une_categorie_dont_seul_le_bareme_livraison_est_positif(): void
    {
        $processus = $this->activerV2();
        $bouteilles = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteilles', 'statut' => 'actif']);
        $this->creerRegle($processus, CommissionCibleType::CODE_PROPRIETAIRE, 0, $bouteilles->id);
        $this->creerRegle($processus, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 1000, $bouteilles->id);

        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('baremes_commission_categories', 1)
                ->where('baremes_commission_categories.0.categorie_id', $bouteilles->id)
                ->where('baremes_commission_categories.0.montant_proprietaire', fn ($val) => (float) $val === 0.0)
                ->where('baremes_commission_categories.0.montant_livraison', fn ($val) => (float) $val === 1000.0)
            );
    }

    /** @test */
    public function inclut_une_categorie_dont_les_deux_baremes_sont_positifs(): void
    {
        $processus = $this->activerV2();
        $bouteilles = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteilles', 'statut' => 'actif']);
        $this->creerRegle($processus, CommissionCibleType::CODE_PROPRIETAIRE, 500, $bouteilles->id);
        $this->creerRegle($processus, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 1000, $bouteilles->id);

        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('baremes_commission_categories', 1)
                ->where('baremes_commission_categories.0.categorie_id', $bouteilles->id)
                ->where('baremes_commission_categories.0.montant_proprietaire', fn ($val) => (float) $val === 500.0)
                ->where('baremes_commission_categories.0.montant_livraison', fn ($val) => (float) $val === 1000.0)
            );
    }

    /** @test */
    public function une_categorie_sans_regle_propre_reprend_les_baremes_globaux_independamment_par_cible(): void
    {
        $processus = $this->activerV2();
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        // Aucune règle catégorie : repli sur le barème global pour chaque cible,
        // résolu indépendamment (Propriétaire global > 0, Livraison globale = 0).
        $this->creerRegle($processus, CommissionCibleType::CODE_PROPRIETAIRE, 650);
        $this->creerRegle($processus, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 0);

        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('baremes_commission_categories', 1)
                ->where('baremes_commission_categories.0.categorie_id', $sachets->id)
                ->where('baremes_commission_categories.0.montant_proprietaire', fn ($val) => (float) $val === 650.0)
                ->where('baremes_commission_categories.0.montant_livraison', fn ($val) => (float) $val === 0.0)
            );
    }

    /** @test */
    public function aucun_processus_v2_actif_ne_propose_aucune_categorie(): void
    {
        // Pas d'activerV2() ici : organisation legacy par défaut.
        Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('baremes_commission_categories', 0)
            );
    }

    /**
     * Véhicule volontairement MIXTE (livraison_vente ET livraison_logistique) : les processus
     * réellement configurables (vente, logistique_transfert — cf.
     * Settings\CommissionRegleController::processusCodesDisponibles(), distribution_client n'en
     * fait plus partie depuis le 01/09/2026) sont donc tous applicables, et cette assertion teste
     * bien la logique "à faire/fait" par CATÉGORIE/bareme, orthogonale à l'applicabilité par
     * USAGE couverte par VehiculeProcessusApplicablesParUsageTest. distribution_client, même
     * explicitement configuré ci-dessous avec un barème positif, ne doit JAMAIS apparaître dans
     * statuts_partage_commission (incident fiche ALARBA du 31/08/2026, puis décision produit du
     * 01/09/2026 qui rend ce processus legacy pour toute organisation, pas seulement Vente-only).
     *
     * @test
     */
    public function expose_le_statut_du_partage_pour_chaque_processus(): void
    {
        $vente = $this->activerV2();
        // Processus legacy toujours créable en base (ex: import historique) mais jamais résolu
        // par aucun appelant depuis le 01/09/2026 — configuré ici pour prouver qu'il n'apparaît
        // jamais, même avec un barème positif qui aurait autrefois produit "à faire".
        $distribution = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_DISTRIBUTION_CLIENT,
            'libelle' => 'Distribution client',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
        $transfert = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
            'libelle' => 'Transfert logistique',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteilles',
            'statut' => 'actif',
        ]);
        $this->creerRegle($vente, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 250, $categorie->id);
        $this->creerRegle($distribution, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 300, $categorie->id);
        $this->creerRegle($transfert, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 400, $categorie->id);

        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $vehicule->proprietaire_id,
            'is_active' => true,
        ]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'processus_id' => $vente->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 250,
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicule.equipe_membres.0.livreur_id', $livreur->id)
                ->where("statuts_partage_commission.{$categorie->id}.vente", 'fait')
                ->where("statuts_partage_commission.{$categorie->id}.logistique_transfert", 'a_faire')
                // distribution_client n'est jamais dans codesApplicablesPourVehicule(), même
                // configuré avec un barème positif ci-dessus : sa clé est absente, jamais "à
                // faire" ni "non_requis".
                ->missing("statuts_partage_commission.{$categorie->id}.distribution_client")
                ->has('processus_options', 2)
            );
    }
}
