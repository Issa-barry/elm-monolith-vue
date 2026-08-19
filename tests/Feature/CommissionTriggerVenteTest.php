<?php

namespace Tests\Feature;

use App\Enums\CategorieVehicule;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\CommissionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private function makeVehiculeAvecEquipe(?Organization $org = null, float $tauxProprietaire = 70, float $tauxChauffeur = 20, float $tauxConvoyeur = 10): Vehicule
    {
        $org ??= $this->org;
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
            'taux_commission_proprietaire' => $tauxProprietaire,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $chauffeur->id,
            'taux_commission' => $tauxChauffeur,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $convoyeur->id,
            'taux_commission' => $tauxConvoyeur,
            'role' => 'convoyeur',
            'ordre' => 1,
        ]);

        return $vehicule->fresh();
    }

    /**
     * Véhicule "interne", propriété du propriétaire interne configuré sur l'organisation (cf.
     * Organization::proprietaireInterne()) — le fait qu'un véhicule soit interne ne doit jamais
     * neutraliser sa commission propriétaire : elle suit le même moteur (CommissionCalculator)
     * qu'un véhicule "partenaire", pour le même propriétaire économique de l'entreprise.
     */
    private function makeVehiculeInterneAvecEquipe(?Organization $org = null, float $tauxProprietaire = 70, float $tauxChauffeur = 30): Vehicule
    {
        $org ??= $this->org;
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
            'taux_commission_proprietaire' => $tauxProprietaire,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $chauffeur->id,
            'taux_commission' => $tauxChauffeur,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        return $vehicule->fresh();
    }

    private function makeProduit(?Organization $org = null): Produit
    {
        return $this->makeProduitAvecVariante(
            $org ?? $this->org,
            ['nom' => 'Produit Test'],
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

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    public function test_validation_du_chargement_cree_la_commission_en_statut_creee(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->first();
        $this->assertNotNull($commission);
        // CREEE, pas IMPAYE : ne devient payable qu'à la validation de la période
        // de paiement (cf. CommissionAdjustmentService::activerCommissionsCreees()).
        $this->assertEquals(StatutCommission::CREEE, $commission->statut);
    }

    public function test_commission_calculee_sur_la_quantite_reellement_chargee_pas_la_demandee(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        // 2 packs demandés, seulement 1 chargé.
        $commande = $this->validerChargementComplet($commande, $ligne, quantiteChargee: 1);

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->first();
        $this->assertNotNull($commission);
        // Marge sur 1 pack = (2000 - 1500) × 1 = 500, jamais 1000 (base 2 packs demandés).
        $this->assertEquals(500.0, (float) $commission->montant_commission_totale);
    }

    public function test_double_validation_ne_duplique_pas_la_commission(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        // Rejoue la génération directement (ex: retry technique) : idempotente,
        // protégée par la contrainte unique commande_vente_id.
        CommissionGenerator::generateForCommandeIfMissing($commande->fresh());

        $this->assertEquals(1, CommissionVente::where('commande_vente_id', $commande->id)->count());
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
        $vehicule = $this->makeVehiculeInterneAvecEquipe(tauxProprietaire: 70, tauxChauffeur: 30);
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->firstOrFail();
        $partProprietaire = CommissionPart::where('commission_vente_id', $commission->id)
            ->where('type_beneficiaire', 'proprietaire')
            ->first();

        $this->assertNotNull($partProprietaire);
        $this->assertSame($vehicule->proprietaire_id, $partProprietaire->proprietaire_id);
        $this->assertSame($this->org->proprietaire_interne_id, $partProprietaire->proprietaire_id);
        // Marge totale = (2000 - 1500) × 2 packs = 1000 ; part propriétaire à 70 % = 700.
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
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    public function test_vehicule_sans_equipe_ne_genere_aucune_commission_et_ne_bloque_pas(): void
    {
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
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    // ── Blocage si répartition d'équipe invalide ────────────────────────────

    public function test_validation_bloquee_si_repartition_equipe_ne_totalise_pas_100_pourcent(): void
    {
        // 70 (proprio) + 20 (chauffeur) + 5 (convoyeur) = 95 % ≠ 100 %.
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxChauffeur: 20, tauxConvoyeur: 5);
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->amenerAChargementEnCours($commande);

        $this->expectException(HttpException::class);

        try {
            CommandeVenteService::validerChargement($commande, [[
                'id' => $ligne->id,
                'quantite_chargee' => $ligne->quantite_demandee,
                'type_ecart' => 'conforme',
            ]]);
        } finally {
            // Le chargement ne doit pas être validé : rollback complet de la transaction.
            $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
            $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
        }
    }

    // ── Déclencheur FACTURE_ENCAISSEE ────────────────────────────────────────

    public function test_facture_encaissee_la_validation_du_chargement_ne_genere_aucune_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->validerChargementComplet($commande, $ligne);

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    public function test_facture_encaissee_encaissement_genere_une_commission_en_statut_creee(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);

        $this->encaisserIntegralement($commande);

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->first();
        $this->assertNotNull($commission);
        // CREEE même à l'encaissement : jamais IMPAYE direct, quel que soit le
        // déclencheur — seule la validation de période sort de CREEE.
        $this->assertEquals(StatutCommission::CREEE, $commission->statut);
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

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    /** Double POST d'encaissement (retry) → une seule commission malgré 2 transitions vers PAYEE observées. */
    public function test_facture_encaissee_idempotence_sur_retry(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $this->encaisserIntegralement($commande);
        // Rejoue le recalcul de statut (ex: event modèle rejoué / job relancé) alors
        // que la facture est déjà PAYEE : ne doit pas re-générer.
        $commande->fresh('facture')->facture->recalculStatut();

        $this->assertEquals(1, CommissionVente::where('commande_vente_id', $commande->id)->count());
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

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commandeA->id]);
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
