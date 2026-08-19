<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\CommissionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Preuve de parité Phase 1, à tolérance ZÉRO — pas de delta autorisé (cf. demande
 * explicite : "ANCIEN ≠ V2" doit être détecté, jamais masqué par une tolérance
 * arbitraire). Compare CommissionCalculator (ancien) et CommissionEnveloppeGenerator
 * (nouveau, pont MARGE_OPERATION) sur une matrice de configurations réelles :
 * un livreur, deux livreurs, plusieurs livreurs, propriétaire à 0 %, propriétaire
 * élevé, valeurs décimales, arrondis difficiles, montant nul, cas dégénéré
 * (propriétaire = 100 %).
 *
 * IMPORTANT : MARGE_OPERATION est un pont de compatibilité Phase 1 strictement
 * transitoire — ces tests valident la parité de RE-PLATEFORME, jamais une
 * formule métier cible (cf. conception cible §0.1.1). PAR_UNITE_VENDUE (Phase 2)
 * n'est pas concerné par ces tests.
 */
class CommissionParitePhase1Test extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

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

        CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function makeVehiculeAvecEquipe(float $tauxProprietaire, array $tauxMembres): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 50,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => $tauxProprietaire,
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

    private function makeProduit(float $prixVente, float $prixUsine): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid()],
            ['prix_vente' => $prixVente, 'prix_usine' => $prixUsine],
        );
    }

    private function creerCommandeValideeEtChargee(Vehicule $vehicule, Produit $produit, int $quantite): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);

        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $quantite,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => $quantite * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $quantite,
            'type_ecart' => 'conforme',
        ]]);

        return $commande->fresh();
    }

    /**
     * Coeur de la preuve : pour un scénario donné, calcule l'ancien résultat
     * (CommissionCalculator, formule margin/%), génère le nouveau (V2, pont
     * MARGE_OPERATION), et exige une égalité EXACTE par bénéficiaire — comparaison
     * en chaînes normalisées à 2 décimales, jamais un epsilon flottant.
     */
    private function assertierParfaiteParite(Vehicule $vehicule, Produit $produit, int $quantite, string $libelleScenario): void
    {
        $commande = $this->creerCommandeValideeEtChargee($vehicule, $produit, $quantite);

        $ancien = CommissionCalculator::fromCommande(
            $commande->fresh(['lignes', 'vehicule.equipe.membres.livreur', 'vehicule.proprietaire'])
        );

        CommissionEnveloppeGenerator::genererPourCommandeVenteMargeLegacy($commande);

        $enveloppes = CommissionEnveloppe::where('source_id', $commande->id)->get();
        $this->assertNotEmpty($enveloppes, "[{$libelleScenario}] Aucune enveloppe générée — génération V2 en échec.");

        $totalAncien = $this->fmt($ancien['commission_totale']);
        $totalNouveau = $this->fmt((float) $enveloppes->sum('montant_total'));
        $this->assertSame($totalAncien, $totalNouveau, "[{$libelleScenario}] Total commission : ancien={$totalAncien} nouveau={$totalNouveau}");

        $parts = CommissionEnveloppePart::whereIn('enveloppe_id', $enveloppes->pluck('id'))->get();
        $this->assertCount(
            count($ancien['parts']), $parts,
            "[{$libelleScenario}] Nombre de bénéficiaires différent : ancien=".count($ancien['parts'])." nouveau={$parts->count()}"
        );

        foreach ($ancien['parts'] as $ap) {
            $beneficiaireId = $ap['type_beneficiaire'] === 'proprietaire' ? $ap['proprietaire_id'] : $ap['livreur_id'];
            $np = $parts->first(fn ($p) => $p->beneficiaire_id === $beneficiaireId);

            $this->assertNotNull($np, "[{$libelleScenario}] Bénéficiaire {$beneficiaireId} ({$ap['type_beneficiaire']}) absent du nouveau moteur.");

            $ancienMontant = $this->fmt($ap['montant_brut']);
            $nouveauMontant = $this->fmt((float) $np->montant_brut);

            $this->assertSame(
                $ancienMontant, $nouveauMontant,
                "[{$libelleScenario}] {$ap['type_beneficiaire']} {$beneficiaireId} : ancien={$ancienMontant} nouveau={$nouveauMontant} "
                ."(taux propriétaire={$vehicule->equipe->taux_commission_proprietaire}%)"
            );
        }
    }

    private function fmt(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }

    // ── Matrice de scénarios ──────────────────────────────────────────────────

    /** @test */
    public function un_seul_livreur(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 60, tauxMembres: [40]);
        $produit = $this->makeProduit(2000, 1500);
        $this->assertierParfaiteParite($vehicule, $produit, 3, 'un seul livreur, 60/40');
    }

    /** @test */
    public function deux_livreurs_repartition_inegale(): void
    {
        // Exemple donné par le métier : propriétaire 60%, A 25%, B 15%.
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 60, tauxMembres: [25, 15]);
        $produit = $this->makeProduit(2500, 1800);
        $this->assertierParfaiteParite($vehicule, $produit, 5, 'deux livreurs 60/25/15');
    }

    /** @test */
    public function plusieurs_livreurs_quatre_membres(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 40, tauxMembres: [20, 15, 15, 10]);
        $produit = $this->makeProduit(3000, 2200);
        $this->assertierParfaiteParite($vehicule, $produit, 8, 'quatre livreurs');
    }

    /** @test */
    public function proprietaire_a_zero_pourcent(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 0, tauxMembres: [70, 30]);
        $produit = $this->makeProduit(1800, 1200);
        $this->assertierParfaiteParite($vehicule, $produit, 4, 'propriétaire à 0%');
    }

    /** @test */
    public function proprietaire_tres_eleve(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 99, tauxMembres: [1]);
        $produit = $this->makeProduit(2000, 1000);
        $this->assertierParfaiteParite($vehicule, $produit, 6, 'propriétaire à 99%');
    }

    /** @test */
    public function proprietaire_a_cent_pourcent_livreurs_a_zero_cas_degenere(): void
    {
        // Cas dégénéré : l'enveloppe livraison vaut 0 GNF, mais la composition du
        // groupe doit rester valide (cf. correctif CommissionGroupeSyncService::calculerRescale).
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 100, tauxMembres: [0, 0]);
        $produit = $this->makeProduit(2000, 1500);
        $this->assertierParfaiteParite($vehicule, $produit, 3, 'propriétaire 100%, livreurs à 0%');

        $enveloppeLivraison = CommissionEnveloppe::where('cible_type', 'equipe_livraison')->firstOrFail();
        $this->assertSame('0.00', $this->fmt((float) $enveloppeLivraison->montant_total));
    }

    /** @test */
    public function taux_decimaux_avec_arrondis_difficiles(): void
    {
        // 33.34 + 33.33 + 33.33 = 100.00 exactement, mais chaque tiers pris
        // isolément ne l'est pas — cas classique de dérive d'arrondi.
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 33.34, tauxMembres: [33.33, 33.33]);
        $produit = $this->makeProduit(2137, 1489);
        $this->assertierParfaiteParite($vehicule, $produit, 7, 'taux décimaux 33.34/33.33/33.33');
    }

    /** @test */
    public function marge_nulle_prix_vente_egal_prix_usine(): void
    {
        // prix_vente == prix_usine : commission_totale = 0, tous les bénéficiaires à 0.
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 60, tauxMembres: [25, 15]);
        $produit = $this->makeProduit(1500, 1500);
        $this->assertierParfaiteParite($vehicule, $produit, 5, 'marge nulle (vente au prix usine)');
    }

    /** @test */
    public function marge_negative_clampee_a_zero(): void
    {
        // prix_vente < prix_usine (vente à perte) : l'ancien moteur clampe à 0
        // (max(0, ...)), le nouveau doit reproduire exactement ce clamp.
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 60, tauxMembres: [40]);
        $produit = $this->makeProduit(1000, 1500);
        $this->assertierParfaiteParite($vehicule, $produit, 3, 'marge négative clampée à zéro');
    }

    /** @test */
    public function grande_quantite_avec_montants_eleves(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 55, tauxMembres: [30, 15]);
        $produit = $this->makeProduit(5500, 4100);
        $this->assertierParfaiteParite($vehicule, $produit, 47, 'grande quantité, montants élevés');
    }
}
