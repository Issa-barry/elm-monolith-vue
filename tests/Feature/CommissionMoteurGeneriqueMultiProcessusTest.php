<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionLogistique;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\NatureOperation;
use App\Enums\PrestataireType;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\TransfertLogistiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Vérifications transversales du moteur générique à 3 processus (vente, distribution_client,
 * logistique_transfert) exigées en fin de chantier :
 *  - isolation totale des montants entre processus sur la même catégorie/équipe ;
 *  - cibles dynamiques (Propriétaire/Site/Consultant), jamais câblées en dur sur Livraison ;
 *  - idempotence de la génération (vente, distribution, transfert) ;
 *  - exclusivité mutuelle du moteur legacy et du moteur générique pour le transfert logistique ;
 *  - workflow CommandeVente strictement identique entre vente_standard et distribution_client.
 */
class CommissionMoteurGeneriqueMultiProcessusTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    protected Organization $org;

    protected User $user;

    protected Site $site;

    protected Categorie $categorie;

    protected Produit $produit;

    protected Vehicule $vehicule;

    protected EquipeLivraison $equipe;

    protected Livreur $livreur1;

    protected Livreur $livreur2;

    protected Proprietaire $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        foreach (['ventes.read', 'ventes.create', 'ventes.update', 'logistique.create', 'logistique.read', 'logistique.update'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->assignRole('admin_entreprise');
        $this->user->givePermissionTo(['ventes.read', 'ventes.create', 'ventes.update', 'logistique.read', 'logistique.update']);
        $this->user->sites()->attach($this->site->id, ['role' => 'responsable', 'is_default' => true]);

        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500ml', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $this->proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $this->proprietaire->id,
            'livraison_vente' => true,
            'livraison_logistique' => true,
            'is_active' => true,
            'capacite_packs' => 500,
        ]);

        $this->livreur1 = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->livreur2 = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $this->equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $this->vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $this->livreur1->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $this->livreur2->id, 'role' => 'convoyeur', 'ordre' => 1]);
        $this->vehicule->update(['equipe_livraison_id' => $this->equipe->id]);

        // Défaut réel = FACTURE_ENCAISSEE (cf. Parametre::getDeclencheurCommissionVente()) : sans
        // ce réglage, validerChargement() ne déclencherait jamais la génération automatique dans
        // ces tests (aucun encaissement n'y est simulé).
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function processusPour(string $code): CommissionProcessus
    {
        return CommissionProcessusDefaults::resoudreOuCreer($this->org->id, $code);
    }

    private function creerRegle(CommissionProcessus $processus, string $cibleType, float $montant, ?string $consultantId = null): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => "Règle {$cibleType} {$processus->code}",
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => $cibleType,
            'mode' => $cibleType === CommissionCibleType::CODE_PROPRIETAIRE ? CommissionMode::DIRECT->value : CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'consultant_id' => $consultantId,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function definirPartageCategorie(CommissionProcessus $processus, array $montantsParLivreurId): void
    {
        foreach ($montantsParLivreurId as $livreurId => $montant) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $this->equipe->id,
                'processus_id' => $processus->id,
                'categorie_id' => $this->categorie->id,
                'livreur_id' => $livreurId,
                'part_pourcentage' => 0,
                'montant_unitaire' => $montant,
                'effective_from' => now()->subDay(),
            ]);
        }
    }

    private function creerCommande(NatureOperation $nature, int $qte = 10): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'nature_operation' => $nature->value,
            'statut' => StatutCommandeVente::BROUILLON,
            'commission_eligible_snapshot' => true,
            'total_commande' => $qte * 2000,
        ]);

        $variante = $this->produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => $qte * (float) $variante->prix_vente,
        ]);
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_chargee' => $qte, 'type_ecart' => 'conforme'],
        ]);

        // distribution_client (décision produit du 30/08/2026, révise COMM-004) : hybride
        // vente/logistique — la mission n'est considérée réalisée, et la commission générée,
        // qu'à la validation de réception, jamais au chargement (cf.
        // CommandeVenteService::validerReceptionDistribution()). vente_standard s'arrête ici, sa
        // commission naît au chargement (déclencheur CHARGEMENT_VALIDE de ce test).
        if ($nature === NatureOperation::DISTRIBUTION_CLIENT) {
            CommandeVenteService::validerReceptionDistribution($commande->fresh(), [
                ['id' => $ligne->id, 'quantite_livree' => $qte, 'type_ecart_reception' => 'conforme'],
            ]);
        }

        return $commande->fresh();
    }

    private function makeTransfert(int $qteChargee = 100, int $qteRecue = 100, bool $enReception = false): TransfertLogistique
    {
        $siteDest = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site Destination', 'type' => 'siege', 'localisation' => 'Conakry']);

        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDest->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'statut' => $enReception ? StatutTransfert::RECEPTION : StatutTransfert::CHARGEMENT,
            'date_arrivee_reelle' => $enReception ? now()->toDateString() : null,
            'created_by' => $this->user->id,
        ]);

        $variante = $this->produit->variantePrincipale()->first();
        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $variante->id,
            'quantite_demandee' => $qteChargee,
            'quantite_chargee' => $qteChargee,
            'quantite_recue' => $enReception ? $qteRecue : null,
            'ecart_type' => $enReception ? TypeEcartLogistique::CONFORME->value : null,
        ]);
        $this->seedVarianteStockSuffisant($variante, $this->site);

        return $transfert;
    }

    // ── 1. Isolation vente / distribution sur la même catégorie/équipe ───────

    /** @test */
    public function distribution_client_utilise_ses_propres_montants_isoles_de_vente(): void
    {
        $vente = $this->processusPour(CommissionProcessus::CODE_VENTE);
        $distribution = $this->processusPour(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);

        $this->creerRegle($vente, CommissionCibleType::CODE_PROPRIETAIRE, 600);
        $this->creerRegle($vente, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 300);
        $this->definirPartageCategorie($vente, [$this->livreur1->id => 180, $this->livreur2->id => 120]);

        $this->creerRegle($distribution, CommissionCibleType::CODE_PROPRIETAIRE, 900);
        $this->creerRegle($distribution, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 500);
        $this->definirPartageCategorie($distribution, [$this->livreur1->id => 300, $this->livreur2->id => 200]);

        $commandeVente = $this->creerCommande(NatureOperation::VENTE_STANDARD, 5);
        $commandeDistrib = $this->creerCommande(NatureOperation::DISTRIBUTION_CLIENT, 5);

        $enveloppeVenteProp = CommissionEnveloppe::where('source_id', $commandeVente->id)->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->firstOrFail();
        $enveloppeVenteLiv = CommissionEnveloppe::where('source_id', $commandeVente->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->firstOrFail();
        $enveloppeDistribProp = CommissionEnveloppe::where('source_id', $commandeDistrib->id)->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->firstOrFail();
        $enveloppeDistribLiv = CommissionEnveloppe::where('source_id', $commandeDistrib->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->firstOrFail();

        $this->assertSame(3000.0, (float) $enveloppeVenteProp->montant_total); // 600 × 5
        $this->assertSame(1500.0, (float) $enveloppeVenteLiv->montant_total); // 300 × 5
        $this->assertSame(4500.0, (float) $enveloppeDistribProp->montant_total); // 900 × 5
        $this->assertSame(2500.0, (float) $enveloppeDistribLiv->montant_total); // 500 × 5

        $this->assertSame($vente->id, $enveloppeVenteProp->processus_id);
        $this->assertSame($distribution->id, $enveloppeDistribProp->processus_id);
    }

    // ── 2. Cibles dynamiques (Site, Consultant) pour distribution_client ─────

    /** @test */
    public function distribution_client_resout_dynamiquement_site_et_consultant_sans_rien_coder_en_dur(): void
    {
        $distribution = $this->processusPour(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        $this->creerRegle($distribution, CommissionCibleType::CODE_PROPRIETAIRE, 900);
        $this->creerRegle($distribution, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 500);
        $this->definirPartageCategorie($distribution, [$this->livreur1->id => 300, $this->livreur2->id => 200]);
        $this->creerRegle($distribution, CommissionCibleType::CODE_SITE, 100);

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Abdoulaye',
        ]);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);
        $this->creerRegle($distribution, CommissionCibleType::CODE_CONSULTANT, 50, $consultant->id);

        $commande = $this->creerCommande(NatureOperation::DISTRIBUTION_CLIENT, 5);

        $enveloppeSite = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_SITE)->first();
        $this->assertNotNull($enveloppeSite, 'La cible Site doit être résolue dynamiquement pour distribution_client, sans être câblée en dur sur Livraison.');
        $this->assertSame(500.0, (float) $enveloppeSite->montant_total); // 100 × 5
        $this->assertSame($this->site->id, $enveloppeSite->cible_id);

        $enveloppeConsultant = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_CONSULTANT)->first();
        $this->assertNotNull($enveloppeConsultant, 'La cible Consultant doit être résolue dynamiquement pour distribution_client.');
        $this->assertSame(250.0, (float) $enveloppeConsultant->montant_total); // 50 × 5
        $this->assertSame($consultant->id, $enveloppeConsultant->cible_id);
    }

    // ── 2bis. Visibilité "à régulariser" pour distribution_client ────────────

    /**
     * Correctif du 30/08/2026 : CommandeVenteController::getCommissionGenerationStatut()
     * résolvait le processus concerné en le codant en dur sur CODE_VENTE — pour une commande
     * distribution_client, sa CommissionGenerationAttempt est rattachée au processus
     * distribution_client, donc jamais retrouvée par ce lookup : l'état "à régulariser" restait
     * invisible dans l'UI (même défaut que l'incident CMD-230826-004 que ce mécanisme visait
     * justement à corriger). Repéré en usage réel : barème équipe_livraison configuré pour
     * distribution_client mais aucun partage GNF pour l'équipe du véhicule sur la catégorie.
     */
    /** @test */
    public function distribution_client_avec_bareme_mais_sans_partage_equipe_expose_a_regulariser_dans_le_show(): void
    {
        $distribution = $this->processusPour(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        // Barème positif MAIS aucun definirPartageCategorie() pour ce processus.
        $this->creerRegle($distribution, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 200);

        $commande = $this->creerCommande(NatureOperation::DISTRIBUTION_CLIENT, 5);

        $this->assertDatabaseHas('commission_generation_attempts', [
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $distribution->id,
            'statut' => 'erreur',
        ]);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page
                ->where('commission_generation_statut.value', 'erreur')
            );

        // L'opération commerciale elle-même n'est jamais bloquée par la commission manquante :
        // creerCommande() va jusqu'à la validation de réception pour cette nature (cf. helper),
        // qui aboutit normalement à LIVREE même si sa propre tentative de génération échoue.
        $this->assertSame(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
    }

    // ── 3. Idempotence vente / distribution ──────────────────────────────────

    /** @test */
    public function la_generation_vente_et_distribution_est_idempotente(): void
    {
        $vente = $this->processusPour(CommissionProcessus::CODE_VENTE);
        $this->creerRegle($vente, CommissionCibleType::CODE_PROPRIETAIRE, 600);

        $distribution = $this->processusPour(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        $this->creerRegle($distribution, CommissionCibleType::CODE_PROPRIETAIRE, 900);

        $commandeVente = $this->creerCommande(NatureOperation::VENTE_STANDARD, 5);
        $commandeDistrib = $this->creerCommande(NatureOperation::DISTRIBUTION_CLIENT, 5);

        // confirmer()/validerChargement() ont déjà déclenché une première génération — un second
        // appel direct ne doit jamais dupliquer.
        CommissionEnveloppeGenerator::genererPourCommandeVente($commandeVente);
        CommissionEnveloppeGenerator::genererPourCommandeVente($commandeDistrib);

        $this->assertSame(1, CommissionEnveloppe::where('source_id', $commandeVente->id)->count());
        $this->assertSame(1, CommissionEnveloppe::where('source_id', $commandeDistrib->id)->count());
    }

    // ── 4. Workflow distribution_client — hybride vente/logistique (révise COMM-004) ──

    /**
     * Correctif du 30/08/2026 : distribution_client n'est PLUS un simple clone du workflow
     * vente_standard (contrairement à la décision initiale) — c'est désormais un hybride
     * vente/logistique : commercialement une vente (même CommandeVente/FactureVente, aucun
     * nouveau modèle), mais logistiquement soumise à une validation de réception explicite avant
     * LIVREE. Ce test remplace l'ancien
     * distribution_client_suit_exactement_le_meme_workflow_que_vente_standard(), dont la prémisse
     * (workflow identique) est désormais fausse par décision produit explicite.
     */
    public function distribution_client_est_un_hybride_vente_logistique_pas_un_nouveau_modele(): void
    {
        $this->assertCount(8, StatutCommandeVente::cases(), 'Aucun nouveau statut n\'a été introduit — LIVREE existait déjà.');

        $commande = $this->creerCommande(NatureOperation::DISTRIBUTION_CLIENT, 5);

        // La réception fait partie de creerCommande() pour cette nature (cf. helper) — la
        // commande atteint donc LIVREE, jamais s'arrêter à LIVRAISON_EN_COURS comme avant.
        $this->assertSame(StatutCommandeVente::LIVREE, $commande->statut);
        $this->assertNotNull($commande->chargement_valide_at);
        $this->assertNotNull($commande->reception_validee_at);
        // La facture suit exactement le même mécanisme normal que vente_standard (créée dès la
        // confirmation par CommandeVenteService::creerFactureInitiale()) — aucun mécanisme de
        // facturation dédié n'a été introduit pour la distribution.
        $this->assertNotNull($commande->facture);
        $this->assertSame(1, FactureVente::where('commande_vente_id', $commande->id)->count());
    }

    /** @test */
    public function distribution_client_naccede_pas_a_livree_sans_validation_de_reception(): void
    {
        $distribution = $this->processusPour(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        $this->creerRegle($distribution, CommissionCibleType::CODE_PROPRIETAIRE, 900);

        // Reproduit manuellement le tronc commun de creerCommande() SANS l'étape de réception,
        // pour vérifier l'état intermédiaire.
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value,
            'statut' => StatutCommandeVente::BROUILLON,
            'commission_eligible_snapshot' => true,
            'total_commande' => 5 * 2000,
        ]);
        $variante = $this->produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 5,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 5 * (float) $variante->prix_vente,
        ]);
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_chargee' => 5, 'type_ecart' => 'conforme'],
        ]);

        // Chargement validé : reste en LIVRAISON_EN_COURS, jamais LIVREE — contrairement à
        // vente_standard, aucun mécanisme n'avance distribution_client au-delà sans réception.
        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        // Et surtout : aucune commission générée au chargement pour cette nature, quel que soit
        // le déclencheur organisation (ici CHARGEMENT_VALIDE, qui fonctionne pourtant pour vente).
        $this->assertSame(0, CommissionEnveloppe::where('source_id', $commande->id)->count());

        // Un encaissement avant réception ne fait pas non plus passer en LIVREE (contrairement à
        // vente_standard) — statut et paiement sont deux axes indépendants pour la distribution.
        // Passe réellement par le contrôleur (pas une création directe du modèle) : c'est
        // EncaissementVenteController::store() qui porte le garde-fou testé ici, jamais
        // l'observer du modèle EncaissementVente (qui ne gère que recalculStatut()/
        // cloturerSiComplete(), pas la transition LIVRAISON_EN_COURS → LIVREE).
        $facture = $commande->fresh()->facture;
        $this->post(route('encaissements.store', $facture), [
            'montant' => (float) $facture->montant_net,
            'mode_paiement' => 'especes',
        ])->assertRedirect();

        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut, 'Un encaissement seul ne doit jamais faire passer une distribution en LIVREE.');

        // La validation de réception, elle, fait enfin naître la commission et passe en LIVREE.
        CommandeVenteService::validerReceptionDistribution($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_livree' => 5, 'type_ecart_reception' => 'conforme'],
        ]);

        $this->assertSame(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
        $this->assertSame(1, CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->count());
    }

    /** @test */
    public function distribution_client_ecart_de_reception_recalcule_la_facture_et_la_commission_sans_toucher_au_stock(): void
    {
        $distribution = $this->processusPour(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        $this->creerRegle($distribution, CommissionCibleType::CODE_PROPRIETAIRE, 100);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value,
            'statut' => StatutCommandeVente::BROUILLON,
            'commission_eligible_snapshot' => true,
            'total_commande' => 10 * 2000,
        ]);
        $variante = $this->produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 10,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 10 * (float) $variante->prix_vente,
        ]);
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_chargee' => 10, 'type_ecart' => 'conforme'],
        ]);

        $stockAvantReception = VarianteStock::where('produit_variante_id', $variante->id)
            ->where('site_id', $this->site->id)
            ->value('qte_stock');

        // Le distributeur n'accepte que 8 sur les 10 chargés.
        CommandeVenteService::validerReceptionDistribution($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_livree' => 8, 'type_ecart_reception' => 'manquant'],
        ]);

        $commande = $commande->fresh(['facture', 'lignes']);

        // Facture recalculée sur le réceptionné (8 × 2000 = 16 000), jamais sur le chargé.
        $this->assertSame(16000.0, (float) $commande->total_commande);
        $this->assertSame(16000.0, (float) $commande->facture->montant_net);

        // Commission calculée sur quantite_livree (8), jamais quantite_chargee (10).
        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)
            ->firstOrFail();
        $this->assertSame(800.0, (float) $enveloppe->montant_total); // 100 × 8, jamais 100 × 10

        // Le stock n'est jamais réajusté par l'écart de réception (décision produit du
        // 30/08/2026) : les 2 unités refusées restent sorties, déjà décrémentées au chargement.
        $stockApresReception = VarianteStock::where('produit_variante_id', $variante->id)
            ->where('site_id', $this->site->id)
            ->value('qte_stock');
        $this->assertSame($stockAvantReception, $stockApresReception);
    }

    // ── 5/6. Transfert logistique : exclusivité mutuelle legacy / générique ──

    /** @test */
    public function transfert_non_migre_utilise_uniquement_le_moteur_legacy(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);
        // Pas de règle logistique_transfert configurée => organisation non migrée.

        $transfert = $this->makeTransfert(qteChargee: 100);
        $this->actingAs($this->user);
        TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertDatabaseHas('commissions_logistiques', ['transfert_logistique_id' => $transfert->id]);
        $this->assertSame(
            0,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count(),
            'Une organisation non migrée ne doit jamais produire de CommissionEnveloppe pour un transfert.'
        );
    }

    /** @test */
    public function transfert_migre_utilise_uniquement_le_moteur_generique_et_reste_idempotent(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);

        $logistique = $this->processusPour(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $logistique->update(['statut' => CommissionActivationStatut::ACTIF->value]); // organisation migrée
        $this->creerRegle($logistique, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 200);
        $this->definirPartageCategorie($logistique, [$this->livreur1->id => 120, $this->livreur2->id => 80]);

        $transfert = $this->makeTransfert(qteChargee: 100);
        $this->actingAs($this->user);
        TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertDatabaseMissing('commissions_logistiques', ['transfert_logistique_id' => $transfert->id]);

        $enveloppe = CommissionEnveloppe::where('source_type', TransfertLogistique::class)
            ->where('source_id', $transfert->id)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)
            ->first();
        $this->assertNotNull($enveloppe, 'Une organisation migrée doit produire une CommissionEnveloppe pour le transfert.');
        $this->assertSame(20000.0, (float) $enveloppe->montant_total); // 100 × 200

        // Idempotence : un second appel direct sur le même transfert ne duplique jamais.
        CommissionEnveloppeGenerator::genererPourTransfertLogistique($transfert->fresh(), 'quantite_chargee');
        $this->assertSame(
            1,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count()
        );
    }

    /** @test */
    public function transfert_migre_avec_bareme_mais_sans_partage_equipe_est_marque_a_regulariser_jamais_zero_silencieux(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);

        $logistique = $this->processusPour(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $logistique->update(['statut' => CommissionActivationStatut::ACTIF->value]);
        // Barème Livreur configuré (montant > 0) MAIS aucun partage GNF fixe entre les membres de
        // l'équipe pour ce processus — c'est précisément ce qui doit produire une erreur explicite
        // ("à régulariser"), jamais un 0 silencieux (décision AMOA #4 : le 0 silencieux ne
        // s'applique qu'à l'ABSENCE de barème, pas à un barème positif sans partage résolu).
        $this->creerRegle($logistique, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 200);

        Notification::fake();

        $transfert = $this->makeTransfert(qteChargee: 100);
        $this->actingAs($this->user);
        TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertSame(
            0,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count()
        );
        $this->assertDatabaseHas('commission_generation_attempts', [
            'source_type' => TransfertLogistique::class,
            'source_id' => $transfert->id,
            'statut' => CommissionGenerationStatut::ERREUR->value,
        ]);
        // Le transfert lui-même n'est jamais bloqué par une commission manquante.
        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);
    }
}
