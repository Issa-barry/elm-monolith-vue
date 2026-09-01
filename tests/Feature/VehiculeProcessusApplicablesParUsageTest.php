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
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * "Processus disponible" ≠ "processus obligatoire" (révisé le 31/08/2026, incident fiche ALARBA :
 * un Tricycle Vente-only affichait Distribution client comme « à faire » alors qu'aucune donnée
 * métier ne l'autorise à exercer ce processus). Les processus réellement pertinents pour un
 * véhicule dépendent de ses usages :
 *  - livraison_vente = true  → processus `vente` applicable ;
 *  - livraison_logistique = true → `distribution_client` ET `logistique_transfert` applicables.
 *
 * Source unique du mapping : CommissionProcessusDefaults::codesApplicablesPourVehicule(),
 * consommée à la fois par VehiculeController::show() (onglets/statuts de la fiche véhicule) et
 * EquipeLivraisonController::rules() (validation processus_code) — ces tests couvrent les deux
 * points d'entrée pour qu'aucun ne puisse diverger de l'autre.
 */
class VehiculeProcessusApplicablesParUsageTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read', 'equipes-livraison.create', 'equipes-livraison.update']);
    }

    private function makeVehicule(array $overrides = []): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            ...$overrides,
        ]);
    }

    private function creerProcessus(string $code): CommissionProcessus
    {
        return CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => $code,
            'libelle' => $code,
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function creerRegleLivraison(CommissionProcessus $processus, string $categorieId, float $montant): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorieId,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function makeEquipeAvecChauffeur(Vehicule $vehicule): array
    {
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

        return [$equipe, $livreur];
    }

    // ── VehiculeController::show() — onglets/processus_options ──────────────────

    /** @test */
    public function vente_uniquement_expose_seulement_le_processus_vente(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('processus_options', 1)
                ->where('processus_options.0.value', CommissionProcessus::CODE_VENTE)
                ->where('processus_actif', CommissionProcessus::CODE_VENTE)
            );
    }

    /** @test */
    public function logistique_uniquement_expose_distribution_et_transfert_mais_pas_vente(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('processus_options', 2)
                ->where('processus_options.0.value', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
                ->where('processus_options.1.value', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
                // Vente non applicable : jamais l'onglet actif par défaut pour ce véhicule.
                ->where('processus_actif', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
            );
    }

    /** @test */
    public function vehicule_mixte_expose_les_trois_processus(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('processus_options', 3)
            );
    }

    /** @test */
    public function requete_processus_non_applicable_retombe_sur_le_premier_processus_applicable(): void
    {
        // Reproduit exactement l'URL de l'incident : ?processus=distribution_client sur un
        // véhicule Vente-only — jamais accepté tel quel, jamais un écran cassé.
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule).'?processus=distribution_client')
            ->assertInertia(fn (Assert $page) => $page
                ->where('processus_actif', CommissionProcessus::CODE_VENTE)
            );
    }

    // ── VehiculeController::show() — statuts_partage_commission ──────────────────

    /** @test */
    public function aucun_faux_a_faire_pour_un_processus_non_applicable_a_lusage_du_vehicule(): void
    {
        // Reproduit l'incident fiche ALARBA : un barème Distribution client positif existe pour
        // l'organisation (configuré pour d'autres véhicules logistiques), mais CE véhicule est
        // Vente-only — distribution_client ne doit jamais apparaître, ni "à faire" ni "non_requis".
        $vente = $this->creerProcessus(CommissionProcessus::CODE_VENTE);
        $distribution = $this->creerProcessus(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);

        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteilles', 'statut' => 'actif']);
        $this->creerRegleLivraison($vente, $categorie->id, 250);
        $this->creerRegleLivraison($distribution, $categorie->id, 300);

        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);
        [$equipe, $livreur] = $this->makeEquipeAvecChauffeur($vehicule);

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
        // Aucun partage distribution_client configuré : sous l'ancienne logique, ce montant
        // resterait "à faire" malgré l'usage Vente-only du véhicule.

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->where("statuts_partage_commission.{$categorie->id}.vente", 'fait')
                ->missing("statuts_partage_commission.{$categorie->id}.distribution_client")
            );
    }

    // ── EquipeLivraisonController — validation processus_code par usage ─────────

    private function validPayload(Vehicule $vehicule, string $processusCode): array
    {
        return [
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
            'processus_code' => $processusCode,
            'membres' => [[
                'livreur_id' => null,
                'nom_complet' => 'Mamadou Diallo',
                'telephone' => '+224620000001',
                'role' => 'chauffeur',
                'ordre' => 0,
            ]],
        ];
    }

    /** @test */
    public function refuse_processus_code_logistique_pour_un_vehicule_vente_uniquement(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_DISTRIBUTION_CLIENT))
            ->assertSessionHasErrors('processus_code');

        $this->assertDatabaseMissing('equipes_livraison', ['vehicule_id' => $vehicule->id]);
    }

    /** @test */
    public function refuse_processus_code_vente_pour_un_vehicule_logistique_uniquement(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_VENTE))
            ->assertSessionHasErrors('processus_code');
    }

    /** @test */
    public function accepte_processus_code_distribution_pour_un_vehicule_logistique_uniquement(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_DISTRIBUTION_CLIENT))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', ['vehicule_id' => $vehicule->id]);
    }

    /** @test */
    public function update_refuse_aussi_processus_code_non_applicable_a_lusage_du_vehicule(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);
        [$equipe] = $this->makeEquipeAvecChauffeur($vehicule);

        $this->actingAs($this->user)
            ->put(route('equipes-livraison.update', $equipe), $this->validPayload($vehicule, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT))
            ->assertSessionHasErrors('processus_code');
    }

    // ── Changement d'usage : jamais de suppression de l'historique déjà enregistré ─

    /** @test */
    public function changement_dusage_met_a_jour_les_processus_applicables_sans_toucher_au_partage_deja_enregistre(): void
    {
        $vente = $this->creerProcessus(CommissionProcessus::CODE_VENTE);
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteilles', 'statut' => 'actif']);
        $this->creerRegleLivraison($vente, $categorie->id, 250);

        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);
        [$equipe, $livreur] = $this->makeEquipeAvecChauffeur($vehicule);

        $partage = EquipeLivraisonPartageCategorie::create([
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
            ->assertInertia(fn (Assert $page) => $page->has('processus_options', 1));

        // Le véhicule devient mixte : distribution_client/logistique_transfert deviennent
        // applicables — sans qu'aucune migration ni suppression ne soit nécessaire.
        $vehicule->update(['livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page->has('processus_options', 3));

        // Le partage Vente déjà enregistré reste intact, jamais implicitement clos ou supprimé
        // par le seul changement d'usage du véhicule.
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'id' => $partage->id,
            'effective_to' => null,
            'montant_unitaire' => 250,
        ]);
    }
}
