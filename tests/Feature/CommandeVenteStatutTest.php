<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Tests du workflow de statut des commandes vente.
 *
 * Workflow : BROUILLON → A_CHARGER → CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS → LIVREE → CLOTUREE
 *                       ↘ ANNULEE (depuis BROUILLON ou A_CHARGER seulement)
 *
 * Routes testées :
 *   POST  /ventes/{id}/statut/avancer  (CommandeVenteStatutController::avancer)
 *   POST  /ventes/{id}/statut/annuler  (CommandeVenteStatutController::annuler)
 */
class CommandeVenteStatutTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete']);

        // Ce fichier teste le workflow de statut (dont la génération de commission au moment du
        // chargement), indépendamment du déclencheur par défaut de l'organisation (devenu
        // FACTURE_ENCAISSEE le 18/08/2026, cf. Parametre::getDeclencheurCommissionVente()) —
        // fixé explicitement ici pour ne jamais dépendre de ce défaut.
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Défaut',
            'statut' => 'actif',
        ]);

        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
        // Barème global : 100/pack pour l'équipe (partagé selon EquipeLivraisonPartageCategorie),
        // 50/pack pour le propriétaire — indépendants l'un de l'autre (chaque cible a sa propre
        // enveloppe), contrairement à l'ancien schéma où un seul pourcentage partagé déterminait
        // les trois parts depuis la même marge.
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 100,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Propriétaire — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 50,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    /**
     * Crée une commande avec un produit, un véhicule et une ligne, et la fait
     * réellement progresser (confirmer / demarrerChargement / validerChargement)
     * jusqu'au statut cible — pour que facture/commissions reflètent fidèlement
     * ce que produirait le workflow réel à ce stade.
     *
     * @param  array<string, mixed>  $attrs  Surcharges pour CommandeVente::factory()
     * @return array{commande: CommandeVente, ligne: CommandeVenteLigne, produit: Produit, vehicule: Vehicule}
     */
    private function makeCommandeWithLigne(array $attrs = [], ?Vehicule $vehicule = null, bool $seedStock = true): array
    {
        $cible = $attrs['statut'] ?? StatutCommandeVente::BROUILLON;
        unset($attrs['statut']);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        if ($seedStock) {
            $this->seedVarianteStockSuffisant($produit->variantePrincipale()->first(), $this->defaultSite);
        }

        if (! $vehicule) {
            $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
            $vehicule = Vehicule::factory()->create([
                'organization_id' => $this->org->id,
                'proprietaire_id' => $proprietaire->id,
                'capacite_packs' => 10,
            ]);
        }

        $commande = CommandeVente::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
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

        $commande = $this->avancerJusqua($commande, $ligne, $cible);

        return compact('commande', 'ligne', 'produit', 'vehicule');
    }

    /**
     * Construit directement une commande en CHARGEMENT_EN_COURS SANS passer par confirmer() —
     * qui réserve désormais du stock dès BROUILLON → A_CHARGER (cf. StockReservationService,
     * correctif du 24/08/2026) et refuserait donc la confirmation elle-même pour un site au
     * disponible insuffisant, avant même d'atteindre le chargement. Utilisé uniquement par les
     * tests ci-dessous qui exercent decrementerStock() sur un site dont variante_stocks n'a
     * jamais été touché — un scénario qui ne peut plus survenir via le workflow réel (confirmer()
     * bloquerait déjà), mais dont le comportement de decrementerStock() lui-même reste à couvrir
     * indépendamment.
     */
    private function creerCommandeEnChargementSansReservationPrealable(int $qte = 2): array
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
            'total_commande' => $qte * 2000,
        ]);

        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => $qte * 2000.0,
        ]);

        return compact('commande', 'ligne', 'produit');
    }

    /**
     * Fait progresser une commande BROUILLON jusqu'au statut cible en passant
     * réellement par CommandeVenteService (confirmer / demarrerChargement /
     * validerChargement), pour que facture et commissions soient créées comme
     * le ferait l'application.
     */
    private function avancerJusqua(CommandeVente $commande, CommandeVenteLigne $ligne, StatutCommandeVente $cible): CommandeVente
    {
        if ($cible === StatutCommandeVente::BROUILLON) {
            return $commande;
        }

        // En production, ces transitions n'ont jamais lieu hors d'une requête
        // authentifiée (created_by du mouvement de stock en dépend) — on
        // s'assure donc ici d'un utilisateur courant, comme le ferait le
        // vrai workflow HTTP.
        $this->actingAs($this->user);

        CommandeVenteService::confirmer($commande);
        if ($cible === StatutCommandeVente::A_CHARGER) {
            return $commande->fresh();
        }

        CommandeVenteService::demarrerChargement($commande);
        if ($cible === StatutCommandeVente::CHARGEMENT_EN_COURS) {
            return $commande->fresh();
        }

        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $ligne->quantite_demandee,
            'type_ecart' => 'conforme',
        ]]);

        return $commande->fresh();
    }

    /**
     * Crée un véhicule avec une équipe à 2 membres (chauffeur + convoyeur).
     */
    /** $montantChauffeur/$montantConvoyeur : montants GNF fixes, doivent sommer au barème équipe (100, cf. setUp()). */
    private function makeVehiculeAvecEquipe(int $montantChauffeur = 58, int $montantConvoyeur = 42): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $convoyeur->id, 'role' => 'convoyeur', 'ordre' => 1]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id, 'categorie_id' => $this->categorie->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'livreur_id' => $chauffeur->id, 'part_pourcentage' => 0,
            'montant_unitaire' => $montantChauffeur, 'effective_from' => now()->subDay(),
        ]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id, 'categorie_id' => $this->categorie->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'livreur_id' => $convoyeur->id, 'part_pourcentage' => 0,
            'montant_unitaire' => $montantConvoyeur, 'effective_from' => now()->subDay(),
        ]);

        return $vehicule->fresh();
    }

    // ── BROUILLON → A_CHARGER ─────────────────────────────────────────────────

    public function test_avancer_confirme_brouillon_en_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);
    }

    public function test_confirmer_exige_vehicule(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id, 'quantite_demandee' => 1,
            'prix_usine_snapshot' => 1500.0, 'prix_vente_snapshot' => 2000.0, 'total_ligne' => 2000.0,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    public function test_confirmer_exige_au_moins_une_ligne(): void
    {
        ['vehicule' => $vehicule] = $this->makeCommandeWithLigne();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    // ── A_CHARGER → CHARGEMENT_EN_COURS ──────────────────────────────────────

    public function test_avancer_demarre_chargement_depuis_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
    }

    public function test_confirmer_cree_la_facture_en_statut_creee(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'total_commande' => 4000,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_brut' => 4000,
            'statut_facture' => 'creee',
        ]);
    }

    public function test_confirmer_cree_facture_avec_meme_reference(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $fresh = $commande->fresh();
        $facture = $fresh->facture;

        $this->assertNotNull($facture);
        $this->assertEquals($fresh->reference, $facture->reference);
    }

    /**
     * La commission de vente naît du chargement réel (validerChargement()),
     * jamais de la confirmation : aucune ligne commission_enveloppes ne doit
     * exister tant que le chargement n'a pas été validé.
     */
    public function test_confirmer_ne_cree_aucune_commission(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        ['commande' => $commande] = $this->makeCommandeWithLigne([], $vehicule);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    public function test_demarrer_chargement_ne_recree_pas_la_facture_et_ne_cree_toujours_pas_de_commission(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ], $vehicule);

        $factureId = $commande->fresh()->facture->id;

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $fresh = $commande->fresh();
        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $fresh->statut);
        $this->assertEquals($factureId, $fresh->facture->id);
        $this->assertEquals(1, FactureVente::where('commande_vente_id', $commande->id)->count());
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    // ── CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS ──────────────────────────────

    public function test_avancer_valide_chargement_avec_quantites(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 2,
                    'type_ecart' => 'conforme',
                    'commentaire_ecart' => null,
                ]],
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        $this->assertEquals(2, $ligne->fresh()->quantite_chargee);
    }

    public function test_avancer_valide_chargement_enregistre_ecart(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 1,
                    'type_ecart' => 'manquant',
                    'commentaire_ecart' => 'Un pack endommagé',
                ]],
            ])
            ->assertRedirect();

        $freshLigne = $ligne->fresh();
        $this->assertEquals(1, $freshLigne->quantite_chargee);
        $this->assertEquals('manquant', $freshLigne->type_ecart->value);
        $this->assertEquals('Un pack endommagé', $freshLigne->commentaire_ecart);
    }

    public function test_avancer_valide_chargement_recalcule_totaux_sur_ecart(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
            'total_commande' => 4000,
        ]);

        // La facture existe déjà (créée à 4000 dès la confirmation, sur la quantité demandée).
        // Démarre le chargement.
        $this->actingAs($this->user)->post(route('ventes.statut.avancer', $commande))->assertRedirect();

        // Valide le chargement avec une quantité chargée inférieure à la demandée (40 au lieu de 60 dans le cas réel ; ici 1 au lieu de 2)
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 1,
                    'type_ecart' => 'manquant',
                    'commentaire_ecart' => 'Casse',
                ]],
            ])
            ->assertRedirect();

        $freshCommande = $commande->fresh();
        $freshLigne = $ligne->fresh();

        // 1 × prix_vente_snapshot (2000) = 2000, pas 4000 (basé sur la quantité demandée)
        $this->assertEquals(2000, (float) $freshLigne->total_ligne);
        $this->assertEquals(2000, (float) $freshCommande->total_commande);

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_brut' => 2000,
            'montant_net' => 2000,
        ]);
    }

    public function test_valider_chargement_cree_les_commissions_chauffeur_et_convoyeur_selon_qte_chargee(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(montantChauffeur: 58, montantConvoyeur: 42);
        ['commande' => $commande, 'ligne' => $ligne, 'vehicule' => $vehicule] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ], $vehicule);

        // Aucune commission tant que le chargement n'est pas validé.
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);

        // Valide le chargement avec seulement 1 pack chargé au lieu de 2.
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 1,
                    'type_ecart' => 'manquant',
                ]],
            ])
            ->assertRedirect();

        $chauffeurId = $vehicule->equipe->membres->firstWhere('role', 'chauffeur')->livreur_id;
        $convoyeurId = $vehicule->equipe->membres->firstWhere('role', 'convoyeur')->livreur_id;

        $commission = $commande->fresh()->commissions()
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->first();
        $this->assertNotNull($commission);
        $parts = $commission->parts()->get()->keyBy('beneficiaire_id');

        // Commission calculée directement sur la quantité chargée (1 pack, jamais
        // les 2 demandées) : montant fixe chauffeur=58, convoyeur=42 × 1 pack.
        $this->assertEquals(58.0, (float) $parts[$chauffeurId]->montant_brut);
        $this->assertEquals(42.0, (float) $parts[$convoyeurId]->montant_brut);
        // Créée seulement — ne devient IMPAYE qu'à la validation de la période de paiement.
        $this->assertEquals('creee', $commission->statut->value);
        $this->assertEquals('creee', $parts[$chauffeurId]->statut->value);
        $this->assertEquals('creee', $parts[$convoyeurId]->statut->value);
    }

    public function test_valider_chargement_decremente_le_stock_du_site(): void
    {
        // Aucune équipe assignée sur ce véhicule (cf. creerCommandeEnChargementSansReservationPrealable) :
        // la sortie de stock doit avoir lieu même quand la commission est
        // silencieusement ignorée faute d'équipe.
        ['commande' => $commande, 'ligne' => $ligne, 'produit' => $produit] = $this->creerCommandeEnChargementSansReservationPrealable();

        $variante = $produit->variantePrincipale()->first();
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $variante->id,
            'site_id' => $this->defaultSite->id,
            'qte_stock' => 50,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [['id' => $ligne->id, 'quantite_chargee' => 2, 'type_ecart' => 'conforme']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $variante->id,
            'site_id' => $this->defaultSite->id,
            'qte_stock' => 48,
        ]);
        $this->assertEquals(48, $produit->fresh()->qte_stock);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $variante->id,
            'site_id' => $this->defaultSite->id,
            'type' => 'sortie',
            'quantite' => 2,
            'stock_avant' => 50,
            'stock_apres' => 48,
            'source_type' => CommandeVenteLigne::class,
            'source_id' => $ligne->id,
        ]);
    }

    public function test_valider_chargement_najamais_herite_du_stock_legacy_au_premier_mouvement_du_site(): void
    {
        // Produit jamais touché par un ajustement manuel de stock : le seul stock
        // connu est l'agrégat global Produit::qte_stock (pas encore de ligne
        // variante_stocks pour aucun site). Décision produit (régression
        // multi-agences) : ce legacy n'est JAMAIS hérité implicitement par le
        // premier site touché. Le site démarre à 0 : le chargement est donc
        // refusé (stock insuffisant), jamais silencieusement clampé (cf.
        // correctif du 23/08/2026 — suppression du clamp silencieux).
        ['commande' => $commande, 'ligne' => $ligne, 'produit' => $produit] = $this->creerCommandeEnChargementSansReservationPrealable();
        $produit->update(['qte_stock' => 1000]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [['id' => $ligne->id, 'quantite_chargee' => 80, 'type_ecart' => 'surplus']],
            ])
            ->assertSessionHasErrors('statut');

        $this->assertDatabaseMissing('variante_stocks', [
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->defaultSite->id,
        ]);
        // Le legacy n'est jamais consulté ni modifié par ce refus.
        $this->assertEquals(1000, $produit->fresh()->qte_stock);
    }

    public function test_valider_chargement_refuse_si_stock_site_insuffisant(): void
    {
        ['commande' => $commande, 'ligne' => $ligne, 'produit' => $produit] = $this->creerCommandeEnChargementSansReservationPrealable();

        // Aucun VarianteStock existant pour cette variante/site avant validation.

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [['id' => $ligne->id, 'quantite_chargee' => 2, 'type_ecart' => 'conforme']],
            ])
            ->assertSessionHasErrors('statut');

        // Refusé avant toute écriture : aucune ligne variante_stocks créée, la commande
        // reste en CHARGEMENT_EN_COURS (cf. correctif du 23/08/2026 — suppression du
        // clamp silencieux à 0).
        $this->assertDatabaseMissing('variante_stocks', [
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->defaultSite->id,
        ]);
        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
    }

    public function test_relancer_validation_chargement_ne_cree_pas_de_doublons(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ], $vehicule);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [['id' => $ligne->id, 'quantite_chargee' => 2, 'type_ecart' => 'conforme']],
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        // Une enveloppe par cible (équipe_livraison + propriétaire) — jamais une seule
        // commission partagée comme dans l'ancien schéma.
        $nbEnveloppesAvant = $commande->fresh()->commissions()->count();
        $nbPartsAvant = $commande->fresh()->commissions()->get()->sum(fn ($e) => $e->parts()->count());

        // Toute nouvelle tentative d'avancer depuis LIVRAISON_EN_COURS est refusée par la policy.
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [['id' => $ligne->id, 'quantite_chargee' => 2, 'type_ecart' => 'conforme']],
            ])
            ->assertStatus(403);

        $this->assertEquals(1, FactureVente::where('commande_vente_id', $commande->id)->count());
        $this->assertEquals($nbEnveloppesAvant, $commande->fresh()->commissions()->count());
        $this->assertEquals($nbPartsAvant, $commande->fresh()->commissions()->get()->sum(fn ($e) => $e->parts()->count()));
    }

    public function test_encaissement_interdit_tant_que_chargement_non_valide(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $facture = $commande->fresh()->facture;
        $this->assertNotNull($facture);
        $this->assertEquals('creee', $facture->statut_facture->value);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertStatus(422);
    }

    // Paiement direct de commission vente désormais impossible depuis aucun écran
    // (can_pay toujours false — la seule chaîne de paiement valide passe par
    // Comptabilité > Fiches de paiement, cf. CommissionVenteController::index()) : le
    // scénario "paiement refusé tant que le chargement n'est pas validé" n'a plus de
    // route à exercer.

    public function test_valider_chargement_sans_quantite_chargee_retourne_erreur(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        // Pas de lignes envoyées → quantite_chargee reste null sur la ligne
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    // ── Transition invalide depuis LIVRAISON_EN_COURS ─────────────────────────

    public function test_avancer_depuis_livraison_en_cours_retourne_403(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
        ]);

        // La policy avancerStatut retourne false pour LIVRAISON_EN_COURS
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertStatus(403);
    }

    // ── Auto-transition LIVRAISON_EN_COURS → LIVREE via encaissement ──────────

    public function test_encaissement_auto_passe_livraison_en_cours_a_livree(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        // La facture (4000, statut IMPAYEE) a été créée et activée par le workflow réel.
        $facture = $commande->fresh()->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
    }

    public function test_encaissement_partiel_ne_cloture_pas(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        $facture = $commande->fresh()->facture;

        // Encaissement partiel
        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
    }

    // ── Auto-clôture LIVREE → CLOTUREE ────────────────────────────────────────

    public function test_encaissement_complet_depuis_livraison_cloture_la_commande(): void
    {
        // Non éligible aux commissions : ce test porte sur la transition de clôture
        // elle-même, pas sur la génération de commission — un véhicule éligible SANS
        // équipe configurée échouerait désormais sa génération (ERREUR) et bloquerait
        // à raison la clôture (cf. cloturerSiComplete(), incident CMD-230826-004).
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
            'commission_eligible_snapshot' => false,
        ]);

        $facture = $commande->fresh()->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 4000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::CLOTUREE, $commande->fresh()->statut);
    }

    // ── Annulation via statut.annuler ──────────────────────────────────────────

    public function test_annuler_statut_depuis_brouillon(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_statut_depuis_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'doublon',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_statut_depuis_chargement_en_cours_retourne_403(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertStatus(403);
    }

    public function test_annuler_statut_sans_motif_echoue(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation_code');
    }

    public function test_annuler_statut_avec_autre_exige_detail(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'autre',
            ])
            ->assertSessionHasErrors('motif_annulation_detail');
    }

    public function test_annuler_statut_stocke_motif_et_detail(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'autre',
                'motif_annulation_detail' => 'Stock manquant en entrepôt',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'id' => $commande->id,
            'motif_annulation' => 'autre : Stock manquant en entrepôt',
        ]);
    }
}
