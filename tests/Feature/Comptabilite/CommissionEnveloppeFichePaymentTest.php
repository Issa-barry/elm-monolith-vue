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
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\CommissionAdjustmentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Chaîne complète : vente → enveloppe → parts → période → fiche → paiement
 * → journal financier, et lecture depuis Commission vente/propriétaire. Seule
 * source de vérité : commission_enveloppe_parts.montant_verse, quel que soit
 * l'écran depuis lequel le paiement a été enregistré — jamais deux circuits
 * de paiement pour la commission de vente.
 *
 * Le générateur lui-même (CommissionEnveloppeGenerator) est déjà couvert par
 * CommissionEnveloppeGeneratorReglesTest — ce fichier teste uniquement l'AVAL
 * (période/fiche/paiement/ajustement).
 */
class CommissionEnveloppeFichePaymentTest extends TestCase
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

    /** @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreur: Livreur, proprietaire: Proprietaire} */
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

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreur' => $livreur, 'proprietaire' => $proprietaire];
    }

    private function creerCommandeEtGenererCommission(Vehicule $vehicule, EquipeLivraison $equipe, Livreur $livreur): CommandeVente
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 1000,
            'effective_from' => now()->subDay(),
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

        $this->seedVarianteStockSuffisant($variante, $this->defaultSite);

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
     * valider chaque part de vente encore non validée (bloquant sinon la
     * validation de la période, cf. CommissionAdjustmentService::partsNonValidees()),
     * puis valider la période via la vraie route (déclenche activerCommissionsCreees()).
     */
    private function validerPeriodeReellement(PaiementPeriode $periode): void
    {
        $parts = CommissionAdjustmentService::partsPourPeriode($periode);
        CommissionAdjustmentService::validerLot($parts, $this->user);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertSessionHas('success');
    }

    // ── Génération → parts ───────────────────────────────────────────────────

    /** @test */
    public function la_generation_cree_des_enveloppes_avec_montant_verse_a_zero(): void
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

    // ── Génération de fiche via PeriodeCalculatorService (calculerLivreurs) ──

    /** @test */
    public function le_calcul_de_periode_genere_une_fiche_a_partir_des_enveloppes(): void
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

    // ── Écrans Commission vente : lecture seule, jamais de paiement direct ────

    /** @test */
    public function lecran_commission_vente_affiche_la_commission_sans_bouton_payer(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        // Période validée avant lecture : la commission passe CREEE → IMPAYE (cf.
        // valider_la_periode_active_les_enveloppes_encore_creees ci-dessous). Le cas
        // "encore CREEE, visible mais non payable" est couvert séparément par
        // CommissionVenteStatutCreeeTest — décision produit du 20/08/2026, une
        // commission CREEE reste toujours visible.
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

    // ── Validation de période : bascule CREEE→IMPAYE sur les enveloppes ──

    /** @test */
    public function valider_la_periode_active_les_enveloppes_encore_creees(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        $this->assertSame(StatutCommission::CREEE, $enveloppeLiv->statut);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        // Une commission de vente non encore validée bloque la validation de la
        // période (cf. CommissionAdjustmentService::partsNonValidees()) — valider
        // chaque part d'abord, exactement comme le workflow réel l'exige.
        $parts = CommissionAdjustmentService::partsPourPeriode($periode);
        CommissionAdjustmentService::validerLot($parts, $this->user);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertSessionHas('success');

        $this->assertSame(StatutCommission::IMPAYE, $enveloppeLiv->fresh()->statut);
        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut);
    }

    // ── Ajustement ────────────────────────────────────────────────────────────

    /** @test */
    public function ajuster_le_montant_dune_part_cree_un_ajustement_et_invalide_sa_validation(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', 'equipe_livraison')->firstOrFail();
        $part = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLiv->id)->firstOrFail();

        $this->actingAs($this->user)
            ->patch(route('comptabilite.ajustements.ajuster', ['vente', $part->id]), [
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

    // ── Visibilité des commissions CREEE (décision produit du 20/08/2026) ────
    // « À partir du moment où une commission existe en base, elle doit être toujours visible
    // dans l'IHM » : une commission CREEE n'est jamais masquée, seulement non payable tant
    // que sa période n'est pas validée. Voir CommissionKpiBuckets pour la ventilation
    // total_genere/en_attente_periode/payable/deja_paye.

    /** @test */
    public function une_commission_creee_reste_visible_sur_lindex_commission_vente(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $beneficiaire = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);

        $this->assertNotNull($beneficiaire, 'Un livreur dont la seule commission est CREEE doit rester visible.');
        $this->assertSame('creee', $beneficiaire['commission_status']);
        $this->assertSame('en_attente', $beneficiaire['display_status']);
        $this->assertFalse($beneficiaire['can_pay']);
        $this->assertSame(10_000.0, $beneficiaire['total_genere']);
        $this->assertSame(10_000.0, $beneficiaire['en_attente_periode']);
        $this->assertSame(0.0, $beneficiaire['payable']);

        $kpis = $response->viewData('page')['props']['kpis'];
        $this->assertSame(10_000.0, $kpis['total_genere']);
        $this->assertSame(10_000.0, $kpis['en_attente_periode']);
        $this->assertSame(0.0, $kpis['payable']);
    }

    /** @test */
    public function le_filtre_statut_creee_retrouve_le_livreur_sur_lindex_commission_vente(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => 'creee']))
            ->assertOk();

        $beneficiaires = collect($response->viewData('page')['props']['beneficiaires']);
        $this->assertCount(1, $beneficiaires);
        $this->assertSame($livreur->id, $beneficiaires->first()['beneficiaire_id']);
    }

    /** @test */
    public function une_commission_creee_reste_visible_sur_le_detail_commission_vente(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->has('commission_details', 1)
                ->where('commission_details.0.statut', 'Créée')
                // Littéraux entiers (pas 10_000.0) : un float PHP sans décimale se
                // sérialise en JSON sans ".0", et AssertableJson::where() compare
                // strictement (===) contre la valeur décodée — donc un int ici.
                ->where('commission_summary.total_genere', 10_000)
                ->where('commission_summary.en_attente_periode', 10_000)
                ->where('commission_summary.payable', 0)
                ->where('commission_summary.net_a_payer', 10_000)
                ->where('payable', false)
            );
    }

    /** @test */
    public function une_commission_creee_reste_visible_sur_lindex_commission_proprietaire(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur, 'proprietaire' => $proprietaire] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.index'))
            ->assertOk();

        $beneficiaire = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $proprietaire->id);

        $this->assertNotNull($beneficiaire, 'Un propriétaire dont la seule commission est CREEE doit rester visible.');
        $this->assertSame('creee', $beneficiaire['commission_status']);
        $this->assertFalse($beneficiaire['can_pay']);
        $this->assertSame(5_000.0, $beneficiaire['total_genere']);
        $this->assertSame(5_000.0, $beneficiaire['en_attente_periode']);
        $this->assertSame(0.0, $beneficiaire['payable']);

        $kpis = $response->viewData('page')['props']['kpis'];
        $this->assertSame(5_000.0, $kpis['total_genere']);
        $this->assertSame(5_000.0, $kpis['en_attente_periode']);
    }

    /** @test */
    public function une_commission_creee_reste_visible_sur_le_detail_commission_proprietaire(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur, 'proprietaire' => $proprietaire] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.show', $proprietaire->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Comptabilite/CommissionProprietaire/Show')
                ->has('commission_details', 1)
                ->where('commission_details.0.statut', 'Créée')
                // Littéraux entiers : cf. commentaire équivalent dans le test livreur ci-dessus.
                ->where('commission_summary.total_genere', 5_000)
                ->where('commission_summary.en_attente_periode', 5_000)
                ->where('commission_summary.payable', 0)
                ->where('payable', false)
            );
    }

    /**
     * Après validation de la période, la commission bascule CREEE → IMPAYE : le montant sort du
     * compartiment "en attente de période" pour entrer dans "payable" — jamais perdu, jamais
     * dupliqué (total_genere reste strictement identique avant/après).
     */
    /** @test */
    public function apres_validation_de_la_periode_le_montant_bascule_den_attente_vers_payable(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);
        $this->validerPeriodeReellement($periode);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $beneficiaire = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);

        $this->assertSame('impaye', $beneficiaire['statut_global']);
        $this->assertSame(10_000.0, $beneficiaire['total_genere']);
        $this->assertSame(0.0, $beneficiaire['en_attente_periode']);
        $this->assertSame(10_000.0, $beneficiaire['payable']);
    }

    /**
     * Page Commande (Ventes/Show) : le badge de statut commission doit refléter une commission
     * encore CREEE, jamais rester vide comme s'il n'existait aucune commission — cf.
     * CommandeVenteController::getCommissionStatutGlobal(), qui lit la relation commissions().
     */
    /** @test */
    public function la_page_commande_affiche_le_statut_creee_dune_commission(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ventes/Show')
                ->where('commission_statut.value', 'creee')
                ->where('commission_statut.label', 'Créée')
            );
    }

    /** Isolation multi-organisation : une commission CREEE d'une autre organisation ne doit jamais fuiter. */
    /** @test */
    public function une_commission_creee_dune_autre_organisation_najamais_fuiter_dans_lindex(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $autreOrg = Organization::factory()->create();
        $autreUser = $this->makeUserWithPermissions($autreOrg, ['comptabilite.read', 'comptabilite.payer']);
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre site', 'type' => 'depot', 'localisation' => 'Conakry']);
        $autreUser->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);

        $response = $this->actingAs($autreUser)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $beneficiaires = collect($response->viewData('page')['props']['beneficiaires']);
        $this->assertCount(0, $beneficiaires);
    }
}
