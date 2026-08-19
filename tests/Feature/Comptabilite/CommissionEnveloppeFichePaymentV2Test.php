<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\MotifAjustementCommission;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\CommissionAdjustmentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Chaîne complète V2 : vente → enveloppe → parts → période → fiche → paiement
 * → journal financier, et lecture depuis Commission vente/propriétaire. Seule
 * source de vérité : commission_enveloppe_parts.montant_verse, quel que soit
 * l'écran depuis lequel le paiement a été enregistré (cf. décision AMOA —
 * jamais deux circuits de paiement pour une organisation V2).
 *
 * Le générateur lui-même (CommissionEnveloppeGenerator) est déjà couvert par
 * CommissionEnveloppeGeneratorReglesTest — ce fichier teste uniquement l'AVAL
 * (période/fiche/paiement/ajustement), qui n'existait pas avant ce chantier.
 */
class CommissionEnveloppeFichePaymentV2Test extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'comptabilite.read', 'comptabilite.payer']);

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

    /** @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreur: Livreur} */
    private function makeVehiculeAvecEquipe(): array
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
        ]);

        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreur' => $livreur];
    }

    private function creerCommandeEtGenererCommission(Vehicule $vehicule, EquipeLivraison $equipe, Livreur $livreur): CommandeVente
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 100,
        ]);

        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 500, CommissionScopeType::CATEGORIE, $categorie->id);
        $this->creerRegle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 1000, CommissionScopeType::CATEGORIE, $categorie->id);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test', 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 20000,
        ]);

        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 10,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 10 * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [
            ['id' => $ligne->id, 'quantite_chargee' => 10, 'type_ecart' => 'conforme'],
        ]);

        $commande = $commande->fresh();
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        return $commande;
    }

    private function periodeCouvrantAujourdhui(): PaiementPeriode
    {
        return app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::LIVREUR,
            now(),
            $this->user->id,
        );
    }

    /**
     * Reproduit le workflow réel avant qu'une période ne devienne payable :
     * valider chaque part de vente V2 encore non validée (bloquant sinon la
     * validation de la période, cf. CommissionAdjustmentService::partsNonValideesV2()),
     * puis valider la période via la vraie route (déclenche activerCommissionsCreeesV2()).
     */
    private function validerPeriodeReellement(PaiementPeriode $periode): void
    {
        $parts = CommissionAdjustmentService::partsPourPeriodeV2($periode);
        CommissionAdjustmentService::validerLotV2($parts, $this->user);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertSessionHas('success');
    }

    // ── Génération → parts ───────────────────────────────────────────────────

    /** @test */
    public function la_commission_v2_genere_des_enveloppes_avec_montant_verse_a_zero(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        $part = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLiv->id)->firstOrFail();

        $this->assertSame(10000.0, (float) $enveloppeLiv->montant_total); // 1000 × 10
        $this->assertSame(0.0, (float) $enveloppeLiv->montant_verse);
        $this->assertSame(0.0, (float) $part->montant_verse);
        $this->assertSame(10000.0, $part->montant_restant);
    }

    // ── Génération de fiche via PeriodeCalculatorService (calculerLivreursV2) ──

    /** @test */
    public function le_calcul_de_periode_genere_une_fiche_v2_a_partir_des_enveloppes(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $periode = $this->periodeCouvrantAujourdhui();
        $result = app(PeriodeCalculatorService::class)->calculer($periode);

        $this->assertSame(1, $result['nb_fiches']);

        $fiche = PaiementFiche::where('periode_id', $periode->id)
            ->where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $livreur->id)
            ->firstOrFail();

        $this->assertSame(10000.0, (float) $fiche->montant_net);
        $this->assertSame('CommissionEnveloppePart', class_basename($fiche->lignes()->first()->source_type));
    }

    // ── Paiement via Fiches de paiement → répercuté sur commission_enveloppe_parts ──

    /** @test */
    public function payer_la_fiche_met_a_jour_montant_verse_sur_la_part_et_lenveloppe(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);
        $this->validerPeriodeReellement($periode);

        $fiche = PaiementFiche::where('periode_id', $periode->id)->where('beneficiaire_id', $livreur->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 4000,
                'mode_paiement' => 'especes',
                'date_paiement' => now()->toDateString(),
            ])
            ->assertRedirect();

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        $part = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLiv->id)->firstOrFail();

        $this->assertSame(4000.0, (float) $part->montant_verse);
        $this->assertSame(StatutCommission::PARTIEL, $part->statut);
        $this->assertSame(4000.0, (float) $enveloppeLiv->fresh()->montant_verse);

        $fiche->refresh();
        $this->assertSame(StatutFichePaiement::PARTIELLEMENT_PAYE, $fiche->statut);

        // Paiement complémentaire jusqu'au solde restant.
        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 6000,
                'mode_paiement' => 'virement',
                'date_paiement' => now()->toDateString(),
            ]);

        $this->assertSame(10000.0, (float) $part->fresh()->montant_verse);
        $this->assertSame(StatutCommission::PAYE, $part->fresh()->statut);
    }

    /** @test */
    public function suppression_du_paiement_desalloue_montant_verse_sur_la_part(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE->value]);

        $fiche = PaiementFiche::where('periode_id', $periode->id)->where('beneficiaire_id', $livreur->id)->firstOrFail();

        $this->actingAs($this->user)->post(route('comptabilite.fiches.paiements.store', $fiche), [
            'montant' => 10000,
            'mode_paiement' => 'especes',
            'date_paiement' => now()->toDateString(),
        ]);

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        $part = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLiv->id)->firstOrFail();
        $this->assertSame(StatutCommission::PAYE, $part->fresh()->statut);

        $paiement = $fiche->historiquePaiements()->firstOrFail();

        $this->actingAs($this->user)
            ->delete(route('comptabilite.fiches.paiements.destroy', $paiement))
            ->assertRedirect();

        $this->assertSame(0.0, (float) $part->fresh()->montant_verse);
        $this->assertSame(StatutCommission::IMPAYE, $part->fresh()->statut);
    }

    // ── Écrans Commission vente : lecture seule pour V2 ──────────────────────

    /** @test */
    public function lecran_commission_vente_affiche_la_commission_v2_sans_bouton_payer(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        // Une commission CREEE (pas encore activée par la validation de période) est
        // exclue de l'écran, exactement comme en Legacy — on valide la période d'abord.
        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);
        $this->validerPeriodeReellement($periode);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Comptabilite/CommissionVente/Index')
            ->where('can_payer', false)
            ->where('beneficiaires.0.beneficiaire_id', $livreur->id)
            ->where('beneficiaires.0.can_pay', false)
        );

        $detail = $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.livreur', $livreur->id));
        $detail->assertOk();
        $detail->assertInertia(fn ($page) => $page
            ->component('Comptabilite/CommissionVente/Livreur/Show')
            ->where('can_payer', false)
            ->where('payable', false)
        );
    }

    /** @test */
    public function le_paiement_direct_est_refuse_pour_une_organisation_v2(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.vente.livreur.paiements', $livreur->id), [
                'montant' => 1000,
                'mode_paiement' => 'especes',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    // ── Validation de période : bascule CREEE→IMPAYE sur les enveloppes V2 ──

    /** @test */
    public function valider_la_periode_active_les_enveloppes_v2_encore_creees(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        $this->assertSame(StatutCommission::CREEE, $enveloppeLiv->statut);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        // Une commission de vente V2 non encore validée bloque la validation de la
        // période (cf. CommissionAdjustmentService::partsNonValideesV2()) — valider
        // chaque part d'abord, exactement comme le workflow réel l'exige.
        $parts = CommissionAdjustmentService::partsPourPeriodeV2($periode);
        CommissionAdjustmentService::validerLotV2($parts, $this->user);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertSessionHas('success');

        $this->assertSame(StatutCommission::IMPAYE, $enveloppeLiv->fresh()->statut);
        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut);
    }

    // ── Ajustement V2 ─────────────────────────────────────────────────────────

    /** @test */
    public function ajuster_le_montant_dune_part_v2_cree_un_ajustement_et_invalide_sa_validation(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        $part = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLiv->id)->firstOrFail();

        $this->actingAs($this->user)
            ->patch(route('comptabilite.ajustements.ajuster', ['vente_v2', $part->id]), [
                'montant' => 7000,
                'motif' => MotifAjustementCommission::CORRECTION->value,
                'commentaire' => 'Correction test',
            ])
            ->assertRedirect();

        $part->refresh();
        $this->assertSame(7000.0, (float) $part->montant_actuel);
        $this->assertNull($part->validated_at);

        $this->assertDatabaseHas('commission_part_adjustments', [
            'commission_part_type' => CommissionEnveloppePart::class,
            'commission_part_id' => $part->id,
            'nouveau_montant' => 7000,
        ]);
    }
}
