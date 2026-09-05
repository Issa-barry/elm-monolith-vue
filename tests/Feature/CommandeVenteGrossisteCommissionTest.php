<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Enums\PrestataireType;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Règle métier critique du 05/09/2026 (cf. docs/grossiste.md) : pour un Grossiste, la commission
 * de transfert logistique (propriétaire/équipe) dépend du mode de remise, mais la commission
 * consultant en est indépendante — elle doit être générée dans les deux cas (Enlèvement ET
 * Livraison) si une règle consultant active existe. Verrouille aussi la non-régression : cette
 * exception reste strictement scopée à GROSSISTE (Externe en vente directe continue de ne générer
 * aucune commission, consultant compris — cf. VehiculeCommandeContextResolver, comportement
 * historique inchangé).
 */
class CommandeVenteGrossisteCommissionTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        // Le défaut organisation est FACTURE_ENCAISSEE depuis le 18/08/2026 — fixé ici
        // explicitement pour que validerChargement() déclenche bien la commission dans le test
        // Livraison (cf. CommissionTriggerVenteTest, même convention).
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dépôt Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => false]);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille d\'eau',
            'statut' => 'actif',
        ]);

        $this->processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function grossisteClient(): Client
    {
        return Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
    }

    private function externeClient(): Client
    {
        return Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::EXTERNE->value,
        ]);
    }

    private function creerConsultantActifEtDesigne(int $montant = 200): Prestataire
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Consultant — catégorie',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'mode' => 'direct',
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'Diallo', 'prenom' => 'Abdoulaye']);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);

        CommissionConsultantAffectation::create([
            'organization_id' => $this->org->id,
            'prestataire_id' => $consultant->id,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        return $consultant;
    }

    private function makeVehiculeAvecEquipeEtPartage(): Vehicule
    {
        // CODE_PROPRIETAIRE et CODE_EQUIPE_LIVRAISON exigent chacun une CommissionRegle propre
        // pour entrer dans $lignesParCible (cf. CommissionEnveloppeGenerator::genererDepuisContexte()
        // — absence de règle = 0 pour CETTE cible, jamais une erreur) : le montant de la règle
        // équipe devient l'« enveloppe unitaire » ensuite répartie entre livreurs via
        // EquipeLivraisonPartageCategorie (montants fixes, jamais un pourcentage).
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Propriétaire — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 350,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 300,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 100,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);

        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'categorie_id' => $this->categorie->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'livreur_id' => $chauffeur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 300,
            'effective_from' => now()->subDay(),
        ]);

        return $vehicule->fresh();
    }

    private function makeProduit(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_grossiste_enlevement_genere_la_commission_consultant_mais_pas_la_logistique(): void
    {
        $this->creerConsultantActifEtDesigne(montant: 200);
        $produit = $this->makeProduit();
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $this->grossisteClient()->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'mode_remise_grossiste' => ModeRemiseGrossiste::ENLEVEMENT->value,
            'commission_eligible_snapshot' => false,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 18500,
            'prix_vente_snapshot' => 18500,
            'total_ligne' => 37000,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
        ]);
        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
        ]);
        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
        ]);
    }

    public function test_grossiste_livraison_genere_la_commission_logistique_et_consultant_normalement(): void
    {
        $this->creerConsultantActifEtDesigne(montant: 200);
        $vehicule = $this->makeVehiculeAvecEquipeEtPartage();
        $produit = $this->makeProduit();
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehicule->id,
            'client_id' => $this->grossisteClient()->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'mode_remise_grossiste' => ModeRemiseGrossiste::LIVRAISON->value,
            'commission_eligible_snapshot' => true,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 15000,
            'prix_vente_snapshot' => 19000,
            'total_ligne' => 38000,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande->fresh(), [[
            'id' => $ligne->id,
            'quantite_chargee' => 2,
            'type_ecart' => 'conforme',
        ]]);

        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
        ]);
    }

    /**
     * Non-régression critique : l'exception "consultant indépendant du véhicule" est strictement
     * scopée à GROSSISTE (cf. CommissionEnveloppeGenerator::genererPourCommandeVente()). Un client
     * Externe en vente directe (comportement historique, inchangé) ne doit générer AUCUNE
     * commission, même avec une règle consultant active et désignée.
     */
    public function test_externe_vente_directe_ne_genere_toujours_aucune_commission_meme_avec_regle_consultant_active(): void
    {
        $this->creerConsultantActifEtDesigne(montant: 200);
        $produit = $this->makeProduit();
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $this->externeClient()->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'mode_remise_grossiste' => null,
            'commission_eligible_snapshot' => false,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => 4000,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }
}
