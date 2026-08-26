<?php

namespace Tests\Feature;

use App\Enums\CategorieVehicule;
use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Naissance de la commission de vente — CommandeVenteService::validerChargement() /
 * CommissionTriggerService / CommissionGenerator. Le calcul lui-même
 * (CommissionCalculator) n'est pas retesté ici, seulement le QUAND et le SUR
 * QUOI (cf. CommandeVenteCommissionEligibiliteTest pour le calcul).
 *
 * Le déclencheur organisation (Parametre::getDeclencheurCommissionVente) ne
 * choisit QUE le moment de naissance de la commission, jamais son statut
 * initial : elle naît toujours CREEE, quel que soit le déclencheur — seule la
 * validation de la période de paiement la fait passer IMPAYE (cf.
 * CommissionAdjustmentService::activerCommissionsCreees()). Les tests de cette
 * classe sans Parametre::set... explicite couvrent le défaut CHARGEMENT_VALIDE.
 */
class CommissionTriggerVenteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        // Les tests de cette classe sans Parametre::set... explicite couvrent le déclencheur
        // CHARGEMENT_VALIDE (cf. docblock de classe) — fixé ici explicitement car ce n'est plus
        // le fallback par défaut de l'organisation depuis le 18/08/2026 (devenu FACTURE_ENCAISSEE,
        // cf. Parametre::getDeclencheurCommissionVente()). La section "Déclencheur
        // FACTURE_ENCAISSEE" plus bas appelle son propre Parametre::set..., qui prévaut sur
        // celui-ci.
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{processus: CommissionProcessus, categorie: Categorie} */
    private function ensureBareme(Organization $org, float $montantEquipe = 300, float $montantProprietaire = 350): array
    {
        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );

        $categorie = Categorie::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Défaut'],
            ['statut' => 'actif'],
        );

        CommissionRegle::firstOrCreate(
            [
                'organization_id' => $org->id,
                'processus_id' => $processus->id,
                'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                'scope_type' => CommissionScopeType::GLOBAL->value,
            ],
            [
                'libelle' => 'Livraison — Global',
                'mode' => CommissionMode::A_REPARTIR->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => $montantEquipe,
                'effective_from' => now()->subDay()->toDateString(),
                'statut' => 'active',
            ],
        );
        CommissionRegle::firstOrCreate(
            [
                'organization_id' => $org->id,
                'processus_id' => $processus->id,
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => CommissionScopeType::GLOBAL->value,
            ],
            [
                'libelle' => 'Propriétaire — Global',
                'mode' => CommissionMode::DIRECT->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => $montantProprietaire,
                'effective_from' => now()->subDay()->toDateString(),
                'statut' => 'active',
            ],
        );

        return ['processus' => $processus, 'categorie' => $categorie];
    }

    /** $montantChauffeur/$montantConvoyeur : montants GNF fixes, doivent sommer au barème équipe (300 par défaut, cf. ensureBareme()). */
    private function makeVehiculeAvecEquipe(?Organization $org = null, int $montantChauffeur = 200, int $montantConvoyeur = 100): Vehicule
    {
        $org ??= $this->org;
        ['categorie' => $categorie] = $this->ensureBareme($org);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $convoyeur->id, 'role' => 'convoyeur', 'ordre' => 1]);

        if ($montantChauffeur > 0) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id, 'categorie_id' => $categorie->id,
                'livreur_id' => $chauffeur->id, 'part_pourcentage' => 0,
                'montant_unitaire' => $montantChauffeur,
                'effective_from' => now()->subDay(),
            ]);
        }
        if ($montantConvoyeur > 0) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id, 'categorie_id' => $categorie->id,
                'livreur_id' => $convoyeur->id, 'part_pourcentage' => 0,
                'montant_unitaire' => $montantConvoyeur,
                'effective_from' => now()->subDay(),
            ]);
        }

        return $vehicule->fresh();
    }

    /**
     * Véhicule "interne", propriété du propriétaire interne configuré sur l'organisation (cf.
     * Organization::proprietaireInterne()) — le fait qu'un véhicule soit interne ne doit jamais
     * neutraliser sa commission propriétaire : elle suit le même moteur
     * (CommissionEnveloppeGenerator) qu'un véhicule "partenaire", pour le même propriétaire
     * économique de l'entreprise.
     */
    private function makeVehiculeInterneAvecEquipe(?Organization $org = null): Vehicule
    {
        $org ??= $this->org;
        ['categorie' => $categorie] = $this->ensureBareme($org);

        $proprietaireInterne = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $org->forceFill(['proprietaire_interne_id' => $proprietaireInterne->id])->save();

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'categorie' => CategorieVehicule::INTERNE,
            'proprietaire_id' => $proprietaireInterne->id,
            'capacite_packs' => 10,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Interne Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id, 'categorie_id' => $categorie->id,
            'livreur_id' => $chauffeur->id, 'part_pourcentage' => 0,
            'montant_unitaire' => 300,
            'effective_from' => now()->subDay(),
        ]);

        return $vehicule->fresh();
    }

    private function makeProduit(?Organization $org = null): Produit
    {
        $org ??= $this->org;
        ['categorie' => $categorie] = $this->ensureBareme($org);

        return $this->makeProduitAvecVariante(
            $org,
            ['nom' => 'Produit Test', 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    /** @return array{commande: CommandeVente, ligne: CommandeVenteLigne} */
    private function creerCommandeAvecLigne(Vehicule $vehicule, Produit $produit, ?Site $site = null, array $attrs = []): array
    {
        $commande = CommandeVente::factory()->create(array_merge([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $site?->id ?? $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ], $attrs));

        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => 4000.0,
        ]);

        $this->seedVarianteStockSuffisant($produit->variantePrincipale()->first(), $site ?? $this->defaultSite);

        return compact('commande', 'ligne');
    }

    /** Fait progresser la commande jusqu'à CHARGEMENT_EN_COURS (avant validation). */
    private function amenerAChargementEnCours(CommandeVente $commande): CommandeVente
    {
        $this->actingAs($this->user);

        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);

        return $commande->fresh();
    }

    /** Fait progresser la commande jusqu'à LIVRAISON_EN_COURS via le vrai service (pas de mock). */
    private function validerChargementComplet(CommandeVente $commande, CommandeVenteLigne $ligne, ?int $quantiteChargee = null): CommandeVente
    {
        $this->amenerAChargementEnCours($commande);

        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $quantiteChargee ?? $ligne->quantite_demandee,
            'type_ecart' => 'conforme',
        ]]);

        return $commande->fresh();
    }

    private function encaisserIntegralement(CommandeVente $commande): void
    {
        $facture = $commande->fresh('facture')->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => $facture->montant_restant,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();
    }

    // ── Naissance de la commission ──────────────────────────────────────────

    public function test_aucune_commission_avant_validation_du_chargement(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->amenerAChargementEnCours($commande);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    public function test_validation_du_chargement_cree_la_commission_en_statut_creee(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $enveloppes = CommissionEnveloppe::where('source_id', $commande->id)->get();
        $this->assertNotEmpty($enveloppes);
        // CREEE, pas IMPAYE : ne devient payable qu'à la validation de la période
        // de paiement (cf. CommissionAdjustmentService::activerCommissionsCreees()).
        $this->assertTrue($enveloppes->every(fn (CommissionEnveloppe $e) => $e->statut === StatutCommission::CREEE));
    }

    public function test_commission_calculee_sur_la_quantite_reellement_chargee_pas_la_demandee(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        // 2 packs demandés, seulement 1 chargé.
        $commande = $this->validerChargementComplet($commande, $ligne, quantiteChargee: 1);

        $total = (float) CommissionEnveloppe::where('source_id', $commande->id)->sum('montant_total');
        $this->assertGreaterThan(0, $total);
        // Barème (300 équipe + 350 propriétaire) × 1 pack = 650, jamais × 2 (base demandée).
        $this->assertEquals(650.0, $total);
    }

    public function test_double_validation_ne_duplique_pas_la_commission(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $count = CommissionEnveloppe::where('source_id', $commande->id)->count();

        // Rejoue la génération directement (ex: retry technique) : idempotente,
        // protégée par l'existence déjà vérifiée dans executerAvecTentative().
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        $this->assertEquals($count, CommissionEnveloppe::where('source_id', $commande->id)->count());
    }

    // ── Véhicule interne : commission propriétaire non neutralisée ──────────

    /**
     * Un véhicule "interne" génère une commission propriétaire pour le propriétaire interne de
     * l'organisation exactement comme un véhicule "partenaire" pour son propriétaire tiers — la
     * catégorie du véhicule ne doit jamais supprimer ni neutraliser cette commission (cf.
     * Organization::proprietaireInterne()).
     */
    public function test_vehicule_interne_genere_une_commission_pour_le_proprietaire_interne(): void
    {
        $vehicule = $this->makeVehiculeInterneAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $enveloppeProprietaire = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)
            ->firstOrFail();
        $partProprietaire = CommissionEnveloppePart::where('enveloppe_id', $enveloppeProprietaire->id)
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PROPRIETAIRE)
            ->first();

        $this->assertNotNull($partProprietaire);
        $this->assertSame($vehicule->proprietaire_id, $partProprietaire->beneficiaire_id);
        $this->assertSame($this->org->proprietaire_interne_id, $partProprietaire->beneficiaire_id);
        // Barème propriétaire (350/pack) × 2 packs = 700, un véhicule interne n'est
        // jamais neutralisé par rapport à un véhicule partenaire.
        $this->assertEqualsWithDelta(700.0, (float) $partProprietaire->montant_brut, 0.01);
    }

    // ── Cas silencieux (pas de commission, pas d'erreur) ────────────────────

    public function test_vehicule_non_eligible_ne_genere_aucune_commission_et_ne_bloque_pas(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne(
            $vehicule, $produit, attrs: ['commission_eligible_snapshot' => false]
        );

        $commande = $this->validerChargementComplet($commande, $ligne);

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    public function test_vehicule_sans_equipe_ne_genere_aucune_commission_et_ne_bloque_pas(): void
    {
        $this->ensureBareme($this->org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        // Tout-ou-rien sur l'ensemble des cibles de la commande (cf.
        // CommissionEnveloppeGenerator::genererParReglesDansTransaction()) : la cible
        // équipe_livraison échoue faute d'équipe, donc même la cible propriétaire
        // (pourtant valide) n'est pas créée.
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    // ── Répartition d'équipe invalide : jamais bloquant pour l'opération commerciale ──

    /**
     * Contrairement à l'ancien moteur (qui bloquait la validation du chargement via une
     * exception si taux_commission_proprietaire + taux_commission ne totalisaient pas 100 %),
     * une répartition manquante ou invalide ne bloque JAMAIS l'opération commerciale : la
     * validation du chargement réussit toujours, seule la génération de la commission pour
     * la cible équipe_livraison échoue silencieusement (tracée dans
     * commission_generation_attempts) — cf. décision AMOA, CommissionEnveloppeGenerator.
     */
    public function test_partage_categorie_manquant_ne_bloque_pas_la_validation_du_chargement(): void
    {
        ['categorie' => $categorie] = $this->ensureBareme($this->org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'capacite_packs' => 10,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Sans Partage',
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => Livreur::factory()->create(['organization_id' => $this->org->id])->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        // Aucun EquipeLivraisonPartageCategorie créé pour cette équipe/catégorie.

        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule->fresh(), $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        // Tout-ou-rien : la cible propriétaire (valide) n'est pas non plus créée.
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
        $this->assertDatabaseHas('commission_generation_attempts', [
            'source_id' => $commande->id,
            'statut' => 'erreur',
        ]);
    }

    // ── Déclencheur FACTURE_ENCAISSEE ────────────────────────────────────────

    public function test_facture_encaissee_la_validation_du_chargement_ne_genere_aucune_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->validerChargementComplet($commande, $ligne);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    public function test_facture_encaissee_encaissement_genere_une_commission_en_statut_creee(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);

        $this->encaisserIntegralement($commande);

        $enveloppes = CommissionEnveloppe::where('source_id', $commande->id)->get();
        $this->assertNotEmpty($enveloppes);
        // CREEE même à l'encaissement : jamais IMPAYE direct, quel que soit le
        // déclencheur — seule la validation de période sort de CREEE.
        $this->assertTrue($enveloppes->every(fn (CommissionEnveloppe $e) => $e->statut === StatutCommission::CREEE));
    }

    /**
     * RÉGRESSION CONFIRMÉE (audit du 2026-08-26) — pas un bug de ce test : le comportement
     * métier CORRECT est asserté ci-dessous, et il échoue contre le code actuel. Preuve :
     * EncaissementVenteController::destroy() autorise la suppression tant que la facture
     * n'est pas ANNULEE, sans vérifier qu'une commission a déjà été générée ;
     * FactureVente::recalculStatut() ne déclenche CommissionTriggerService que sur la
     * transition ENTRANTE vers PAYEE, jamais sur la sortie ; EncaissementVente::deleted()
     * contre-passe l'écriture comptable mais ne touche jamais CommissionEnveloppe/Part.
     * Ne pas assouplir cette assertion pour la faire passer : corriger le moteur, ou
     * documenter explicitement la dette si le produit décide de l'assumer.
     */
    public function test_facture_encaissee_suppression_de_lencaissement_declencheur_devrait_invalider_la_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $this->encaisserIntegralement($commande);

        $facture = $commande->fresh('facture')->facture;
        $this->assertSame('payee', $facture->statut->value, 'précondition : facture payée');
        $enveloppesAvant = CommissionEnveloppe::where('source_id', $commande->id)->get();
        $this->assertNotEmpty($enveloppesAvant, 'précondition : commission générée');

        $encaissement = $facture->encaissements()->sole();
        $this->actingAs($this->user)
            ->delete(route('encaissements.destroy', $encaissement))
            ->assertRedirect();

        $facture->refresh();
        $this->assertNotSame(
            'payee',
            $facture->statut->value,
            'la facture doit redescendre sous PAYEE une fois son seul encaissement supprimé',
        );

        // Comportement CORRECT attendu : plus de fait générateur (facture non payée) = plus
        // de commission active/payable. Échoue aujourd'hui contre le code réel : les
        // enveloppes restent CREEE, intactes, comme si l'encaissement existait toujours.
        $enveloppesApres = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->get();
        $this->assertEmpty(
            $enveloppesApres,
            'RÉGRESSION CONFIRMÉE : la commission reste active malgré la suppression de son fait générateur (facture repassée non-payée). '.
            'Trouvé '.$enveloppesApres->count().' enveloppe(s) toujours non-annulée(s).',
        );
    }

    public function test_facture_encaissee_encaissement_partiel_ne_genere_pas_de_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $facture = $commande->fresh('facture')->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => (float) $facture->montant_restant / 2,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    /** Double POST d'encaissement (retry) → pas de doublon malgré 2 transitions vers PAYEE observées. */
    public function test_facture_encaissee_idempotence_sur_retry(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $this->encaisserIntegralement($commande);
        $count = CommissionEnveloppe::where('source_id', $commande->id)->count();
        // Rejoue le recalcul de statut (ex: event modèle rejoué / job relancé) alors
        // que la facture est déjà PAYEE : ne doit pas re-générer.
        $commande->fresh('facture')->facture->recalculStatut();

        $this->assertEquals($count, CommissionEnveloppe::where('source_id', $commande->id)->count());
    }

    // ── Multi-tenant ─────────────────────────────────────────────────────────

    public function test_parametre_organisation_est_independant(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $orgB = Organization::factory()->create();
        Parametre::setDeclencheurCommissionVente($orgB->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        // Org A (FACTURE_ENCAISSEE) : rien au chargement.
        $vehiculeA = $this->makeVehiculeAvecEquipe();
        $produitA = $this->makeProduit();
        ['commande' => $commandeA, 'ligne' => $ligneA] = $this->creerCommandeAvecLigne($vehiculeA, $produitA);
        $this->validerChargementComplet($commandeA, $ligneA);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commandeA->id]);
        $this->assertEquals(
            DeclencheurCommissionVente::CHARGEMENT_VALIDE,
            Parametre::getDeclencheurCommissionVente($orgB->id),
        );
        $this->assertEquals(
            DeclencheurCommissionVente::FACTURE_ENCAISSEE,
            Parametre::getDeclencheurCommissionVente($this->org->id),
        );
    }
}
