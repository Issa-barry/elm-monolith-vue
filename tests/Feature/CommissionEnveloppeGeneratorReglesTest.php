<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppeLigne;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
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
 * Phase 2 — voie réelle du moteur (`CommissionEnveloppeGenerator::genererPourCommandeVente()`),
 * barèmes fixes PAR_UNITE_VENDUE par catégorie/produit/variante, résolus via
 * CommissionRegleResolver. Le pont Phase 1 (MARGE_OPERATION) n'est jamais
 * exercé par ces tests — cf. CommissionParitePhase1Test pour lui.
 */
class CommissionEnveloppeGeneratorReglesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function creerRegle(string $cibleType, float $montant, CommissionScopeType $scope = CommissionScopeType::GLOBAL, ?string $scopeId = null): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => "Règle {$cibleType}",
            'scope_type' => $scope->value,
            'scope_id' => $scopeId,
            'cible_type' => $cibleType,
            'mode' => $cibleType === CommissionCibleType::CODE_PROPRIETAIRE ? CommissionMode::DIRECT->value : CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function makeVehiculeAvecEquipe(array $tauxMembres): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 100,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);

        foreach ($tauxMembres as $i => $taux) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
            EquipeLivreur::create([
                'equipe_id' => $equipe->id,
                'livreur_id' => $livreur->id,
                'taux_commission' => $taux,
                'role' => $i === 0 ? 'chauffeur' : 'convoyeur',
                'ordre' => $i,
            ]);
        }

        return $vehicule->fresh();
    }

    private function makeProduit(?string $categorieId = null): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $categorieId],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    private function creerCommandeAvecLignes(Vehicule $vehicule, array $produitsEtQuantites): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);

        $lignesData = [];
        foreach ($produitsEtQuantites as [$produit, $quantite]) {
            $variante = $produit->variantePrincipale()->first();
            $ligne = $commande->lignes()->create([
                'variante_id' => $variante->id,
                'quantite_demandee' => $quantite,
                'prix_usine_snapshot' => (float) $variante->prix_usine,
                'prix_vente_snapshot' => (float) $variante->prix_vente,
                'total_ligne' => $quantite * (float) $variante->prix_vente,
            ]);
            $lignesData[] = ['id' => $ligne->id, 'quantite_chargee' => $quantite, 'type_ecart' => 'conforme'];
        }

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, $lignesData);

        return $commande->fresh();
    }

    // ── Barème global ─────────────────────────────────────────────────────────

    /** @test */
    public function genere_selon_le_bareme_global_quand_aucune_regle_categorie_nexiste(): void
    {
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 600);
        $this->creerRegle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 300);

        $vehicule = $this->makeVehiculeAvecEquipe([60, 40]);
        $produit = $this->makeProduit();
        $commande = $this->creerCommandeAvecLignes($vehicule, [[$produit, 5]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppeProp = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'proprietaire')->firstOrFail();
        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();

        $this->assertSame(3000.0, (float) $enveloppeProp->montant_total); // 600 × 5
        $this->assertSame(1500.0, (float) $enveloppeLiv->montant_total); // 300 × 5

        $parts = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLiv->id)->orderBy('montant_brut', 'desc')->get();
        $this->assertEqualsWithDelta(900.0, (float) $parts[0]->montant_brut, 0.01); // 60%
        $this->assertEqualsWithDelta(600.0, (float) $parts[1]->montant_brut, 0.01); // 40%
    }

    // ── Barème catégorie prévaut sur le global ───────────────────────────────

    /** @test */
    public function la_regle_categorie_prevaut_sur_la_regle_globale(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);

        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 600); // global
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 800, CommissionScopeType::CATEGORIE, $categorie->id); // catégorie
        $this->creerRegle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 300);

        $vehicule = $this->makeVehiculeAvecEquipe([100]);
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, [[$produit, 4]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppeProp = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'proprietaire')->firstOrFail();
        $this->assertSame(3200.0, (float) $enveloppeProp->montant_total); // 800 × 4, pas 600 × 4

        $ligneTrace = CommissionEnveloppeLigne::where('enveloppe_id', $enveloppeProp->id)->firstOrFail();
        $this->assertSame($categorie->id, $ligneTrace->categorie_id_snapshot);
    }

    // ── Multi-catégories : une seule enveloppe, plusieurs lignes ─────────────

    /** @test */
    public function une_commande_multi_categories_produit_une_seule_enveloppe_par_cible(): void
    {
        $sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $bouteilles = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteilles', 'statut' => 'actif']);

        $this->creerRegle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 300, CommissionScopeType::CATEGORIE, $sachets->id);
        $this->creerRegle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 400, CommissionScopeType::CATEGORIE, $bouteilles->id);
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 600, CommissionScopeType::CATEGORIE, $sachets->id);
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 800, CommissionScopeType::CATEGORIE, $bouteilles->id);

        $vehicule = $this->makeVehiculeAvecEquipe([100]);
        $produitSachets = $this->makeProduit($sachets->id);
        $produitBouteilles = $this->makeProduit($bouteilles->id);

        $commande = $this->creerCommandeAvecLignes($vehicule, [
            [$produitSachets, 100],
            [$produitBouteilles, 50],
        ]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        // Une seule enveloppe par cible (décision AMOA #6), pas deux.
        $this->assertEquals(1, CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->count());
        $this->assertEquals(1, CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'proprietaire')->count());

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        // 100×300 (Sachets) + 50×400 (Bouteilles) = 30000 + 20000 = 50000.
        $this->assertSame(50000.0, (float) $enveloppeLiv->montant_total);

        $enveloppeProp = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'proprietaire')->firstOrFail();
        // 100×600 + 50×800 = 60000 + 40000 = 100000.
        $this->assertSame(100000.0, (float) $enveloppeProp->montant_total);

        // Deux lignes justificatives par enveloppe (une par catégorie contributrice).
        $this->assertEquals(2, CommissionEnveloppeLigne::where('enveloppe_id', $enveloppeLiv->id)->count());
    }

    // ── Absence de règle = 0, jamais un blocage (décision AMOA #4) ───────────

    /** @test */
    public function absence_de_regle_pour_une_categorie_produit_une_enveloppe_a_zero_sans_erreur(): void
    {
        $categorieSansRegle = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Non configurée', 'statut' => 'actif']);
        // Aucune règle créée du tout, ni globale ni catégorie.

        $vehicule = $this->makeVehiculeAvecEquipe([100]);
        $produit = $this->makeProduit($categorieSansRegle->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, [[$produit, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);

        $tentative = CommissionGenerationAttempt::where('source_id', $commande->id)->firstOrFail();
        $this->assertEquals(\App\Enums\CommissionGenerationStatut::SUCCES, $tentative->statut, 'Absence de règle = succès avec zéro enveloppe, jamais une erreur.');
    }

    // ── Groupe invalide bloque toute la génération (tout ou rien) ───────────

    /** @test */
    public function un_groupe_livraison_introuvable_bloque_toute_la_generation_y_compris_le_proprietaire(): void
    {
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 600);
        $this->creerRegle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 300);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 100,
        ]);
        // Aucune équipe assignée à ce véhicule.

        $produit = $this->makeProduit();
        $commande = $this->creerCommandeAvecLignes($vehicule, [[$produit, 5]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        // Ni le propriétaire ni la livraison n'ont d'enveloppe : tout-ou-rien.
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);

        $tentative = CommissionGenerationAttempt::where('source_id', $commande->id)->firstOrFail();
        $this->assertEquals(\App\Enums\CommissionGenerationStatut::ERREUR, $tentative->statut);
    }
}
