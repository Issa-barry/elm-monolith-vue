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
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Règle métier du 05/09/2026 (cf. docs/grossiste.md) : pour un Grossiste, la commission de
 * transfert logistique (propriétaire/équipe) dépend du mode de remise, mais la commission
 * consultant en est indépendante — elle doit être générée dans les deux cas (Enlèvement ET
 * Livraison) si une règle consultant active existe.
 *
 * Généralisée par le chantier 2A (05/09/2026, indépendance des cibles) : cette indépendance
 * n'est plus scopée à GROSSISTE — tout client sans véhicule (Externe compris) peut désormais
 * générer une commission SITE/CONSULTANT si sa propre règle est active et résolvable ; seules
 * PROPRIETAIRE/EQUIPE_LIVRAISON restent structurellement impossibles sans véhicule, quel que soit
 * le type de client (cf. CommissionEnveloppeGenerator::genererDepuisContexte()).
 *
 * Révisée le 05/09/2026 (chantier « Transfert grossiste », cf. docs/grossiste.md) : un Grossiste
 * en Livraison n'utilise PLUS le processus Vente — il utilise désormais un processus dédié,
 * CommissionProcessus::CODE_TRANSFERT_GROSSISTE, jamais fusionné avec Vente ni avec Transfert
 * logistique (ses bénéficiaires diffèrent des deux, notamment CODE_SITE qui n'est jamais
 * commissionné sur un transfert logistique interne). Un Grossiste en Enlèvement reste sur Vente,
 * inchangé — décision produit explicite, ne pas généraliser.
 */
class CommandeVenteGrossisteCommissionTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    private CommissionProcessus $processus;

    private CommissionProcessus $processusGrossisteLivraison;

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

        $this->processusGrossisteLivraison = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_TRANSFERT_GROSSISTE],
            [
                'libelle' => 'Transfert grossiste',
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

    private function creerConsultantActifEtDesigne(int $montant = 200, ?CommissionProcessus $processus = null): Prestataire
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => ($processus ?? $this->processus)->id,
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

    /**
     * Barème sous le processus TRANSFERT_GROSSISTE (jamais Vente) — c'est désormais le processus
     * réellement consulté pour un Grossiste + Livraison (cf. CommissionProcessusDefaults::
     * identiteCodePourVente()). CODE_PROPRIETAIRE et CODE_EQUIPE_LIVRAISON exigent chacun une
     * CommissionRegle propre pour entrer dans $lignesParCible (cf. CommissionEnveloppeGenerator::
     * genererDepuisContexte() — absence de règle = 0 pour CETTE cible, jamais une erreur) : le
     * montant de la règle équipe devient l'« enveloppe unitaire » ensuite répartie entre livreurs
     * via EquipeLivraisonPartageCategorie (montants fixes, jamais un pourcentage).
     */
    private function makeVehiculeAvecEquipeEtPartage(): Vehicule
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processusGrossisteLivraison->id,
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
            'processus_id' => $this->processusGrossisteLivraison->id,
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
            'processus_id' => $this->processusGrossisteLivraison->id,
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

    /**
     * Vérification du 06/09/2026 : tableau complet des 4 cibles pour Grossiste + Enlèvement.
     * L'absence de véhicule ne bloque QUE les cibles qui en ont besoin pour résoudre leur
     * bénéficiaire (Propriétaire/Livreur) — Consultant ET Site, paramétrés sous le processus VENTE
     * (celui réellement utilisé pour un Enlèvement, cf. COMM-008), sont générés normalement, sans
     * dépendre l'un de l'autre ni d'aucun fallback (indépendance des cibles, chantier 2A).
     */
    public function test_grossiste_enlevement_genere_consultant_et_site_mais_jamais_proprietaire_ni_livreur(): void
    {
        $this->creerConsultantActifEtDesigne(montant: 200);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id, // $this->processus = CODE_VENTE
            'libelle' => 'Site — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 100,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
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

        // Consultant et Site paramétrés → générés, tous deux sous le processus VENTE.
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'processus_id' => $this->processus->id,
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'processus_id' => $this->processus->id,
        ]);
        // Propriétaire/Livreur : structurellement impossibles sans véhicule, quel que soit leur
        // paramétrage — jamais tentés, jamais un fallback qui aurait pu les créer malgré tout.
        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
        ]);
        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
        ]);
    }

    public function test_grossiste_livraison_genere_la_commission_transfert_grossiste_et_consultant_jamais_vente(): void
    {
        $this->creerConsultantActifEtDesigne(montant: 200, processus: $this->processusGrossisteLivraison);
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

        // Les 3 cibles sont bien générées ET rattachées au processus TRANSFERT_GROSSISTE — jamais
        // Vente (chantier « Transfert grossiste », 05/09/2026, cf. docs/grossiste.md).
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'processus_id' => $this->processusGrossisteLivraison->id,
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'processus_id' => $this->processusGrossisteLivraison->id,
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'processus_id' => $this->processusGrossisteLivraison->id,
        ]);
        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_id' => $commande->id,
            'processus_id' => $this->processus->id, // $this->processus = CODE_VENTE
        ]);
    }

    /**
     * Vérification ciblée du 05/09/2026 : reproduit exactement le scénario ayant révélé le bug
     * initial (VTE-050926-002 — 600 unités facturées à tort selon le barème Ventes à 950 GNF/unité)
     * en repartant du parcours réel de bout en bout (store() HTTP → confirmer() →
     * demarrerChargement() → validerChargement()), avec cette fois le barème Transfert grossiste
     * réellement configuré sur les 4 cibles. Les 4 montants doivent tomber exactement juste, et
     * aucune commission ne doit jamais utiliser le barème Vente (950 GNF/unité).
     */
    public function test_parcours_reel_grossiste_livraison_600_unites_genere_exactement_les_4_montants_attendus(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'libelle' => 'Propriétaire — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 800,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'libelle' => 'Livreur — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 200,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'libelle' => 'Site — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 200,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        $this->creerConsultantActifEtDesigne(montant: 50, processus: $this->processusGrossisteLivraison);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 1000,
            'livraison_logistique' => true,
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
            'processus_id' => $this->processusGrossisteLivraison->id,
            'livreur_id' => $chauffeur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 200,
            'effective_from' => now()->subDay(),
        ]);

        $produit = $this->makeProduit();
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $client = $this->grossisteClient();

        // Parcours réel : création via l'endpoint HTTP store(), exactement comme un utilisateur.
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 600, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->firstOrFail();
        $this->assertSame('livraison', $commande->mode_remise_grossiste->value);

        $ligne = $commande->lignes()->firstOrFail();
        // store() confirme déjà automatiquement une commande créée avec véhicule (cf.
        // CommandeVenteController::store() : BROUILLON → A_CHARGER dans la même transaction) —
        // jamais un second appel à confirmer() ici, la commande n'est plus en BROUILLON.
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande->fresh(), [[
            'id' => $ligne->id,
            'quantite_chargee' => 600,
            'type_ecart' => 'conforme',
        ]]);

        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'montant_total' => 480000, // 600 × 800
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'montant_total' => 120000, // 600 × 200
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'montant_total' => 120000, // 600 × 200
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'montant_total' => 30000, // 600 × 50
        ]);

        // Aucune commission ne doit jamais avoir utilisé le barème Vente (950 GNF/unité).
        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_id' => $commande->id,
            'processus_id' => $this->processus->id, // $this->processus = CODE_VENTE
        ]);
    }

    /**
     * Généralisation du 05/09/2026 (chantier 2A, indépendance des cibles) : un client Externe en
     * vente directe (sans véhicule) génère désormais sa commission Consultant si une règle active
     * et désignée existe — l'exception "consultant indépendant du véhicule" n'est plus scopée à
     * GROSSISTE (cf. CommissionEnveloppeGenerator::genererDepuisContexte()). PROPRIETAIRE/
     * EQUIPE_LIVRAISON restent en revanche structurellement impossibles sans véhicule, pour
     * n'importe quel type de client — ce garde-fou-là est inchangé.
     */
    public function test_externe_vente_directe_genere_desormais_la_commission_consultant_mais_jamais_la_logistique(): void
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

    // ── Garde-fou préventif : barème Transfert grossiste non configuré ──────────
    // (CommandeVenteController::ensureTransfertGrossisteBaremeConfigure(), 05/09/2026)

    /**
     * Décision produit du 05/09/2026 (cf. docs/grossiste.md) : contrairement à
     * distribution_client, Transfert grossiste n'a AUCUN repli de barème — une organisation
     * n'ayant configuré aucune CommissionRegle pour ce processus voit sa toute première livraison
     * Grossiste bloquée à la création, avec un message explicite, plutôt que de générer
     * silencieusement 0 commission sur toutes les cibles.
     */
    public function test_grossiste_livraison_est_bloquee_a_la_creation_si_aucun_bareme_transfert_grossiste_configure(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        // $this->processusGrossisteLivraison existe (créé en setUp()) mais SANS AUCUNE
        // CommissionRegle active — exactement l'état d'une organisation qui vient de migrer.
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'capacite_packs' => 100,
            'livraison_logistique' => true,
        ]);
        $produit = $this->makeProduit();
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $this->grossisteClient()->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('vehicule_id');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    /** Symétrique du test précédent : dès qu'AU MOINS une règle existe, la création est autorisée. */
    public function test_grossiste_livraison_est_autorisee_une_fois_un_bareme_transfert_grossiste_configure(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processusGrossisteLivraison->id,
            'libelle' => 'Site — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 200,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'capacite_packs' => 100,
            'livraison_logistique' => true,
        ]);
        $produit = $this->makeProduit();
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $this->grossisteClient()->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    /**
     * Vérification du 05/09/2026 : le garde-fou doit aussi s'appliquer via update(), pas
     * seulement store() — sans quoi éditer un brouillon Grossiste + Enlèvement en lui affectant un
     * véhicule (le faisant ainsi basculer en Livraison) contournait entièrement la vérification du
     * barème Transfert grossiste : la commande n'est jamais recréée, seulement modifiée in situ.
     */
    public function test_grossiste_enlevement_devenant_livraison_via_update_est_aussi_bloquee_sans_bareme(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $produit = $this->makeProduit();
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);
        $client = $this->grossisteClient();

        // Brouillon Grossiste + Enlèvement existant — état atteignable par exemple via un import,
        // une API, ou toute future voie de création qui laisse la commande en BROUILLON (store()
        // HTTP confirme/facture immédiatement, cf. store() : jamais de brouillon persistant à
        // l'issue d'une création normale).
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $client->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'mode_remise_grossiste' => ModeRemiseGrossiste::ENLEVEMENT->value,
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

        // $this->processusGrossisteLivraison existe (créé en setUp()) mais SANS AUCUNE
        // CommissionRegle active — exactement le même état que le test de création équivalent.
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'capacite_packs' => 100,
            'livraison_logistique' => true,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'client_id' => $client->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('vehicule_id');

        // Le brouillon reste inchangé — jamais basculé en Livraison malgré le rejet.
        $this->assertDatabaseHas('commandes_ventes', [
            'id' => $commande->id,
            'vehicule_id' => null,
            'mode_remise_grossiste' => 'enlevement',
        ]);
    }
}
