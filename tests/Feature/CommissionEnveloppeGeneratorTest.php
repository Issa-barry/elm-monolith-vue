<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionGenerationAttempt;
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
 * Moteur générique de génération d'enveloppes (Phase 1 — pont marge_operation,
 * cf. conception cible §0.1.1/§F). Vérifie : la parité numérique avec l'ancien
 * CommissionCalculator, la séparation propriétaire/livraison (décision AMOA #1),
 * l'atomicité tout-ou-rien de la génération, et surtout que l'opération
 * commerciale n'est JAMAIS affectée par un échec de génération (décision AMOA
 * #2, round 2 — §0.1.2 de la conception cible).
 */
class CommissionEnveloppeGeneratorTest extends TestCase
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
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function activerProcessusVente(): CommissionProcessus
    {
        return CommissionProcessus::create([
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
            'capacite_packs' => 10,
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

    private function makeProduit(float $prixVente = 2000, float $prixUsine = 1500): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test'],
            ['prix_vente' => $prixVente, 'prix_usine' => $prixUsine],
        );
    }

    /** @return array{commande: CommandeVente, ligne: CommandeVenteLigne} */
    private function creerCommandeValideeEtChargee(Vehicule $vehicule, Produit $produit, int $quantite = 2): array
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

        return ['commande' => $commande->fresh(), 'ligne' => $ligne->fresh()];
    }

    // ── Parité avec l'ancien moteur ──────────────────────────────────────────

    /** @test */
    public function reproduit_exactement_les_memes_montants_finaux_que_lancien_moteur(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxMembres: [20, 10]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit, quantite: 2);

        // Marge = (2000-1500) × 2 = 1000.
        $ancien = CommissionCalculator::fromCommande($commande->fresh(['lignes', 'vehicule.equipe.membres.livreur', 'vehicule.proprietaire']));

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppes = CommissionEnveloppe::where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)->get();
        $this->assertCount(2, $enveloppes, 'Une enveloppe propriétaire + une enveloppe livraison, jamais un pot unique.');

        $parts = CommissionEnveloppePart::whereIn('enveloppe_id', $enveloppes->pluck('id'))->get();
        $this->assertCount(3, $parts, '1 propriétaire + 2 livreurs.');

        foreach ($ancien['parts'] as $ancienPart) {
            $beneficiaireId = $ancienPart['type_beneficiaire'] === 'proprietaire'
                ? $ancienPart['proprietaire_id']
                : $ancienPart['livreur_id'];

            $nouvellePart = $parts->first(fn ($p) => $p->beneficiaire_id === $beneficiaireId);
            $this->assertNotNull($nouvellePart, "Bénéficiaire {$beneficiaireId} manquant dans le nouveau moteur.");
            $this->assertEqualsWithDelta(
                $ancienPart['montant_brut'],
                (float) $nouvellePart->montant_brut,
                0.02,
                "Montant final différent pour {$beneficiaireId} : ancien={$ancienPart['montant_brut']} nouveau={$nouvellePart->montant_brut}"
            );
        }
    }

    /** @test */
    public function le_proprietaire_est_une_enveloppe_directe_separee_de_la_livraison(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 60, tauxMembres: [40]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppeProprietaire = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', 'proprietaire')->firstOrFail();
        $enveloppeLivraison = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', 'equipe_livraison')->firstOrFail();

        $this->assertNotSame($enveloppeProprietaire->id, $enveloppeLivraison->id);

        $partProprietaire = CommissionEnveloppePart::where('enveloppe_id', $enveloppeProprietaire->id)->firstOrFail();
        $this->assertNull($partProprietaire->taux_repartition_snapshot, 'Le propriétaire ne participe à aucun partage.');

        $partsLivraison = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLivraison->id)->get();
        $this->assertCount(1, $partsLivraison);
        $this->assertNotNull($partsLivraison->first()->taux_repartition_snapshot);
    }

    /** @test */
    public function la_somme_des_parts_livraison_egale_toujours_le_montant_de_lenveloppe(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 50, tauxMembres: [17, 17, 16]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit, quantite: 7);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppeLivraison = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', 'equipe_livraison')->firstOrFail();
        $somme = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLivraison->id)->sum('montant_brut');

        $this->assertEqualsWithDelta((float) $enveloppeLivraison->montant_total, (float) $somme, 0.001);
    }

    // ── Découplage opération / génération (décision AMOA #2, round 2) ───────
    //
    // Important : CommandeVenteService::validerChargement() applique déjà lui-même
    // la validation somme=100% AVANT que la commande n'atteigne l'état "chargement
    // validé" (ancien garde-fou, cf. CommissionTriggerVenteTest). On ne peut donc
    // jamais obtenir une commande validée avec une équipe déjà invalide au moment
    // du chargement. Le scénario réaliste que ces tests couvrent est différent et
    // tout aussi réel : l'équipe est modifiée (par un admin) APRÈS la validation du
    // chargement mais AVANT que la génération de commission ne s'exécute.

    private function corrompreEquipe(Vehicule $vehicule): void
    {
        $vehicule->loadMissing('equipe.membres');
        // 70 + 20 + 5 = 95 % ≠ 100 % : configuration devenue invalide après coup.
        $vehicule->equipe->membres->first()->update(['taux_commission' => 5]);
    }

    /** @test */
    public function un_echec_de_generation_ne_leve_aucune_exception_vers_lappelant(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxMembres: [20, 10]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        $this->corrompreEquipe($vehicule);

        // Ne doit jamais lancer d'exception : l'appelant (ex: un contrôleur
        // d'encaissement) ne doit jamais voir son propre traitement interrompu.
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertTrue(true, 'Aucune exception levée — assertion explicite du contrat.');
    }

    /** @test */
    public function un_echec_de_generation_ne_cree_aucune_enveloppe_et_est_trace_comme_erreur(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxMembres: [20, 10]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        $this->corrompreEquipe($vehicule);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
        ]);

        $tentative = CommissionGenerationAttempt::where('source_id', $commande->id)->firstOrFail();
        $this->assertEquals(CommissionGenerationStatut::ERREUR, $tentative->statut);
        $this->assertNotEmpty($tentative->motif_erreur);
    }

    /** @test */
    public function une_generation_reussie_est_tracee_comme_succes(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxMembres: [30]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $tentative = CommissionGenerationAttempt::where('source_id', $commande->id)->firstOrFail();
        $this->assertEquals(CommissionGenerationStatut::SUCCES, $tentative->statut);
    }

    // ── Retry idempotent (cycle complet régularisation, §D de la conception) ──

    /** @test */
    public function un_appel_deja_genere_est_un_no_op_silencieux_sans_nouvelle_tentative_tracee(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxMembres: [30]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);
        $nbEnveloppesApres1erAppel = CommissionEnveloppe::where('source_id', $commande->id)->count();

        // Rejoue (ex: double déclenchement concurrent) : reconnu comme déjà généré,
        // ne constitue même pas une nouvelle "tentative" — cohérent avec le
        // comportement déjà existant de CommissionGenerator::generateForCommandeIfMissing().
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        $this->assertEquals($nbEnveloppesApres1erAppel, CommissionEnveloppe::where('source_id', $commande->id)->count());
        $this->assertEquals(1, CommissionGenerationAttempt::where('source_id', $commande->id)->count());
    }

    /** @test */
    public function relancer_apres_correction_du_parametrage_genere_avec_succes_sans_dupliquer(): void
    {
        $this->activerProcessusVente();

        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxMembres: [20, 10]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        $this->corrompreEquipe($vehicule);
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
        $this->assertEquals(
            CommissionGenerationStatut::ERREUR,
            CommissionGenerationAttempt::where('source_id', $commande->id)->latest('created_at')->first()->statut,
        );

        // Corrige la configuration (ex: action "Corriger l'équipe" côté admin) puis relance.
        $vehicule->loadMissing('equipe.membres');
        $vehicule->equipe->membres->first()->update(['taux_commission' => 20]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        $this->assertDatabaseHas('commission_enveloppes', ['source_id' => $commande->id, 'cible_type' => 'proprietaire']);
        $this->assertDatabaseHas('commission_enveloppes', ['source_id' => $commande->id, 'cible_type' => 'equipe_livraison']);
        $this->assertEquals(2, CommissionEnveloppe::where('source_id', $commande->id)->count(), 'Toujours deux enveloppes, jamais de doublon.');

        $tentatives = CommissionGenerationAttempt::where('source_id', $commande->id)->orderBy('created_at')->get();
        $this->assertCount(2, $tentatives, 'L\'historique complet reste consultable : la tentative en erreur n\'est jamais effacée.');
        $this->assertEquals(CommissionGenerationStatut::ERREUR, $tentatives[0]->statut);
        $this->assertEquals(CommissionGenerationStatut::SUCCES, $tentatives[1]->statut);

        // Une troisième tentative (rejeu) ne duplique toujours rien.
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());
        $this->assertEquals(2, CommissionEnveloppe::where('source_id', $commande->id)->count());
        $this->assertEquals(2, CommissionGenerationAttempt::where('source_id', $commande->id)->count());
    }

    /** @test */
    public function le_moteur_reste_inerte_si_aucun_processus_nest_active(): void
    {
        // Pas d'activerProcessusVente() ici : organisation non basculée.
        $vehicule = $this->makeVehiculeAvecEquipe(tauxProprietaire: 70, tauxMembres: [30]);
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
        $this->assertDatabaseMissing('commission_generation_attempts', ['source_id' => $commande->id]);
    }
}
