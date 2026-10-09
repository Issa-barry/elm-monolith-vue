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
 *  - livraison_logistique = true → `logistique_transfert` applicable ;
 *  - livraison_grossiste = true → `transfert_grossiste` applicable (usage propre depuis l'ADR 0023).
 *
 * Révisé le 01/09/2026 (décision produit) : `distribution_client` n'est PLUS un processus
 * configurable, quel que soit l'usage du véhicule — une distribution utilise désormais le même
 * barème que `logistique_transfert` (cf. CommissionEnveloppeGenerator::genererPourCommandeVente()).
 *
 * Révisé le 05/09/2026 (chantier « Transfert grossiste », cf. docs/grossiste.md) : un véhicule qui
 * fait de la logistique (`livraison_logistique = true`) expose désormais DEUX onglets logistiques
 * simultanés (`logistique_transfert` ET `transfert_grossiste`, jamais l'un à la place de l'autre —
 * une même équipe peut avoir des montants fixes différents pour chacun sur la même catégorie, cf.
 * equipe_livraison_partages_categorie.processus_id) — l'ancienne limite "2 processus au maximum,
 * jamais 3" ne tient donc plus pour un véhicule mixte (vente + logistique), qui en expose désormais 3.
 *
 * Révisé le 09/10/2026 (ADR 0023) : `transfert_grossiste` dépend de son propre usage
 * (`livraison_grossiste`), jamais plus de `livraison_logistique` — un camion qui ne fait que de la
 * logistique n'expose plus d'onglet Transfert grossiste, et un véhicule peut livrer des grossistes
 * sans faire de logistique.
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
    public function logistique_uniquement_expose_seulement_logistique_transfert(): void
    {
        // ADR 0023 : la logistique seule n'ouvre plus le Transfert grossiste (cas des camions de
        // transfert qui ne livrent jamais de grossiste).
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('processus_options', 1)
                ->where('processus_options.0.value', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
                ->where('processus_actif', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
            );
    }

    /** @test */
    public function grossiste_uniquement_expose_seulement_transfert_grossiste(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => false, 'livraison_grossiste' => true]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('processus_options', 1)
                ->where('processus_options.0.value', CommissionProcessus::CODE_TRANSFERT_GROSSISTE)
                ->where('processus_actif', CommissionProcessus::CODE_TRANSFERT_GROSSISTE)
            );
    }

    /** @test */
    public function vehicule_vente_et_logistique_sans_grossiste_expose_deux_processus(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true, 'livraison_grossiste' => false]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->has('processus_options', 2)
                ->where('processus_options.0.value', CommissionProcessus::CODE_VENTE)
                ->where('processus_options.1.value', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
            );
    }

    /** @test */
    public function vehicule_aux_trois_usages_expose_vente_logistique_transfert_et_transfert_grossiste(): void
    {
        // distribution_client reste absent (jamais configurable, décision du 01/09/2026).
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true, 'livraison_grossiste' => true]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->has('processus_options', 3)
                ->where('processus_options.0.value', CommissionProcessus::CODE_VENTE)
                ->where('processus_options.1.value', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
                ->where('processus_options.2.value', CommissionProcessus::CODE_TRANSFERT_GROSSISTE)
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
    public function refuse_processus_code_distribution_pour_un_vehicule_vente_uniquement(): void
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

    /**
     * Décision produit du 01/09/2026 : distribution_client n'est plus jamais acceptable comme
     * processus_code, même pour le véhicule le plus favorable (logistique-only, qui aurait
     * pourtant accepté ce code avant cette date).
     */
    /** @test */
    public function refuse_processus_code_distribution_meme_pour_un_vehicule_logistique_uniquement(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_DISTRIBUTION_CLIENT))
            ->assertSessionHasErrors('processus_code');

        $this->assertDatabaseMissing('equipes_livraison', ['vehicule_id' => $vehicule->id]);
    }

    /** @test */
    public function accepte_processus_code_logistique_transfert_pour_un_vehicule_logistique_uniquement(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', ['vehicule_id' => $vehicule->id]);
    }

    /**
     * ADR 0023 : Transfert grossiste a son propre usage (livraison_grossiste), indépendant de la
     * logistique — cf. CommissionProcessusDefaults::usageVehiculeRequis().
     */
    /** @test */
    public function accepte_processus_code_transfert_grossiste_pour_un_vehicule_grossiste_uniquement(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => false, 'livraison_grossiste' => true]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_TRANSFERT_GROSSISTE))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', ['vehicule_id' => $vehicule->id]);
    }

    /** @test */
    public function refuse_processus_code_transfert_grossiste_pour_un_vehicule_logistique_sans_usage_grossiste(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true, 'livraison_grossiste' => false]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_TRANSFERT_GROSSISTE))
            ->assertSessionHasErrors('processus_code');

        $this->assertDatabaseMissing('equipes_livraison', ['vehicule_id' => $vehicule->id]);
    }

    /** @test */
    public function refuse_processus_code_transfert_grossiste_pour_un_vehicule_vente_uniquement(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($vehicule, CommissionProcessus::CODE_TRANSFERT_GROSSISTE))
            ->assertSessionHasErrors('processus_code');

        $this->assertDatabaseMissing('equipes_livraison', ['vehicule_id' => $vehicule->id]);
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

        // Le véhicule prend les trois usages : logistique_transfert ET transfert_grossiste
        // deviennent applicables — sans qu'aucune migration ni suppression ne soit nécessaire.
        $vehicule->update(['livraison_logistique' => true, 'livraison_grossiste' => true]);

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
