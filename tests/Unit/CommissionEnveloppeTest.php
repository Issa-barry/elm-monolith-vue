<?php

namespace Tests\Unit;

use App\Enums\CommissionActivationStatut;
use App\Enums\PrestataireType;
use App\Enums\StatutCommission;
use App\Enums\StatutFichePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePaiementItem;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommissionEnveloppeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeEnveloppe(array $overrides = []): CommissionEnveloppe
    {
        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        return CommissionEnveloppe::create(array_merge([
            'organization_id' => $this->org->id,
            'source_type' => 'App\\Models\\CommandeVente',
            'source_id' => (string) Str::ulid(),
            'processus_id' => $processus->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 5000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ], $overrides));
    }

    private function makePart(CommissionEnveloppe $enveloppe, array $overrides = []): CommissionEnveloppePart
    {
        return $enveloppe->parts()->create(array_merge([
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => (string) Str::ulid(),
            'montant_brut' => 5000,
            'montant_net' => 5000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ], $overrides));
    }

    /** Simule un paiement de fiche alloué à cette part (mécanisme réel de recalculStatut()). */
    private function verserSurPart(CommissionEnveloppePart $part, float $montant): void
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'P-'.uniqid(),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => now()->startOfMonth(),
            'date_fin' => now()->endOfMonth(),
            'statut' => 'validee',
        ]);

        $fiche = PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'F-'.uniqid(),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $part->beneficiaire_id,
            'beneficiaire_nom' => 'Test',
            'montant_brut' => $montant,
            'total_deductions' => 0,
            'montant_net' => $montant,
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
        ]);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'montant' => $montant,
            'mode_paiement' => 'especes',
            'date_paiement' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        CommissionEnveloppePaiementItem::create([
            'paiement_id' => $paiement->id,
            'commission_enveloppe_part_id' => $part->id,
            'amount_allocated' => $montant,
        ]);
    }

    // ── Accessor: montant_restant (CommissionEnveloppe) ───────────────────────

    public function test_montant_restant_enveloppe_est_somme_des_parts(): void
    {
        $enveloppe = $this->makeEnveloppe();

        $this->makePart($enveloppe, [
            'beneficiaire_type' => 'livreur',
            'montant_brut' => 3000,
            'montant_net' => 3000,
            'montant_verse' => 1000,
            'statut' => StatutCommission::PARTIEL->value,
        ]);
        $this->makePart($enveloppe, [
            'beneficiaire_type' => 'proprietaire',
            'montant_brut' => 2000,
            'montant_net' => 2000,
            'montant_verse' => 2000,
            'statut' => StatutCommission::PAYE->value,
        ]);

        // Livreur restant: 3000-1000=2000, proprietaire restant: 0 → total 2000
        $this->assertEquals(2000.0, $enveloppe->montant_restant);
    }

    // ── Accessor: montant_restant (CommissionEnveloppePart) ───────────────────

    public function test_montant_restant_part_ne_peut_pas_etre_negatif(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, [
            'montant_net' => 1000,
            'montant_verse' => 1500, // sur-versé
        ]);

        $this->assertEquals(0.0, $part->montant_restant);
    }

    public function test_montant_restant_part_calcule_correctement(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, [
            'montant_net' => 3000,
            'montant_verse' => 1000,
        ]);

        $this->assertEquals(2000.0, $part->montant_restant);
    }

    // ── recalculStatutGlobal ──────────────────────────────────────────────────

    public function test_statut_global_passe_impaye_si_aucun_versement(): void
    {
        $enveloppe = $this->makeEnveloppe(['statut' => StatutCommission::IMPAYE->value]);
        $this->makePart($enveloppe, ['montant_verse' => 0]);

        $enveloppe->recalculStatutGlobal();

        $this->assertEquals(StatutCommission::IMPAYE, $enveloppe->fresh()->statut);
    }

    public function test_statut_global_passe_partiel_si_versement_partiel(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, ['montant_net' => 5000, 'montant_verse' => 0]);

        $this->verserSurPart($part, 2000);
        $part->recalculStatut();

        $this->assertEquals(StatutCommission::PARTIEL, $enveloppe->fresh()->statut);
        $this->assertEquals(2000.0, (float) $enveloppe->fresh()->montant_verse);
    }

    public function test_statut_global_passe_paye_quand_tout_est_verse(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, ['montant_net' => 5000, 'montant_verse' => 0]);

        $this->verserSurPart($part, 5000);
        $part->recalculStatut();

        $this->assertEquals(StatutCommission::PAYE, $enveloppe->fresh()->statut);
        $this->assertEquals(5000.0, (float) $enveloppe->fresh()->montant_verse);
    }

    public function test_statut_global_avec_deux_parts_partiellement_versees(): void
    {
        $enveloppe = $this->makeEnveloppe();

        $partLivreur = $this->makePart($enveloppe, [
            'beneficiaire_type' => 'livreur',
            'montant_net' => 3000,
            'montant_verse' => 0,
        ]);
        $partProp = $this->makePart($enveloppe, [
            'beneficiaire_type' => 'proprietaire',
            'montant_net' => 2000,
            'montant_verse' => 0,
        ]);

        $this->verserSurPart($partLivreur, 1500);
        $this->verserSurPart($partProp, 800);

        $partLivreur->recalculStatut();
        $partProp->recalculStatut();

        $fresh = $enveloppe->fresh();
        $this->assertEquals(StatutCommission::PARTIEL, $fresh->statut);
        $this->assertEquals(2300.0, (float) $fresh->montant_verse);
    }

    public function test_statut_global_recalcul_complet_passe_a_paye(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $this->makePart($enveloppe, ['montant_net' => 3000, 'montant_verse' => 3000, 'statut' => StatutCommission::PAYE->value]);

        $enveloppe->recalculStatutGlobal();

        $this->assertEquals(StatutCommission::PAYE, $enveloppe->fresh()->statut);
    }

    // ── isPaye ────────────────────────────────────────────────────────────────

    public function test_is_paye_retourne_true_quand_statut_paye(): void
    {
        $enveloppe = $this->makeEnveloppe(['statut' => StatutCommission::PAYE->value]);
        $this->assertTrue($enveloppe->isPaye());
    }

    public function test_is_paye_retourne_false_quand_statut_impaye(): void
    {
        $enveloppe = $this->makeEnveloppe(['statut' => StatutCommission::IMPAYE->value]);
        $this->assertFalse($enveloppe->isPaye());
    }

    public function test_statut_contient_exactement_les_valeurs_attendues(): void
    {
        // ANNULEE existe depuis la cascade d'annulation de commande (voir
        // CommandeVenteService::annulerCommissionsAssociees) — le statut "annule"/"cancelled"
        // en anglais n'a en revanche jamais existé.
        $values = array_column(StatutCommission::cases(), 'value');
        $this->assertEqualsCanonicalizing(['creee', 'impaye', 'partiel', 'paye', 'annulee'], $values);
        $this->assertNotContains('annule', $values);
        $this->assertNotContains('cancelled', $values);
    }

    // ── resoudreBeneficiaire ──────────────────────────────────────────────────

    public function test_resoudre_beneficiaire_resout_le_prestataire_pour_type_prestataire(): void
    {
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'Diallo', 'prenom' => 'Abdoulaye']);
        $prestataire = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, [
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PRESTATAIRE,
            'beneficiaire_id' => $prestataire->id,
        ]);

        $resolu = $part->resoudreBeneficiaire();

        $this->assertInstanceOf(Prestataire::class, $resolu);
        $this->assertSame($prestataire->id, $resolu->id);
    }

    public function test_resoudre_beneficiaire_retourne_null_si_le_prestataire_nexiste_plus(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, [
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PRESTATAIRE,
            'beneficiaire_id' => (string) Str::ulid(),
        ]);

        $this->assertNull($part->resoudreBeneficiaire());
    }

    public function test_resoudre_beneficiaire_resout_chaque_type_beneficiaire_connu(): void
    {
        $enveloppe = $this->makeEnveloppe();

        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $partLivreur = $this->makePart($enveloppe, ['beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR, 'beneficiaire_id' => $livreur->id]);
        $this->assertInstanceOf(Livreur::class, $partLivreur->resoudreBeneficiaire());

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $partProp = $this->makePart($enveloppe, ['beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE, 'beneficiaire_id' => $proprietaire->id]);
        $this->assertInstanceOf(Proprietaire::class, $partProp->resoudreBeneficiaire());

        $employe = Employe::factory()->create(['organization_id' => $this->org->id]);
        $partEmploye = $this->makePart($enveloppe, ['beneficiaire_type' => CommissionEnveloppePart::TYPE_EMPLOYE, 'beneficiaire_id' => $employe->id]);
        $this->assertInstanceOf(Employe::class, $partEmploye->resoudreBeneficiaire());

        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site '.uniqid(), 'type' => 'depot', 'localisation' => 'Conakry']);
        $partSite = $this->makePart($enveloppe, ['beneficiaire_type' => CommissionEnveloppePart::TYPE_SITE, 'beneficiaire_id' => $site->id]);
        $this->assertInstanceOf(Site::class, $partSite->resoudreBeneficiaire());
    }

    public function test_resoudre_beneficiaire_retourne_null_pour_un_type_inconnu(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, ['beneficiaire_type' => 'type_inexistant', 'beneficiaire_id' => (string) Str::ulid()]);

        $this->assertNull($part->resoudreBeneficiaire());
    }

    // ── montant_a_payer (ajustement) ──────────────────────────────────────────

    public function test_montant_a_payer_retombe_sur_montant_net_sans_ajustement(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, ['montant_net' => 3000, 'montant_actuel' => null]);

        $this->assertSame(3000.0, $part->montant_a_payer);
    }

    public function test_montant_a_payer_utilise_montant_actuel_quand_ajuste(): void
    {
        $enveloppe = $this->makeEnveloppe();
        $part = $this->makePart($enveloppe, ['montant_net' => 3000, 'montant_actuel' => 2500]);

        // Un ajustement (CommissionAdjustmentService) doit primer sur le montant théorique —
        // le reste à payer se calcule toujours sur montant_a_payer, jamais montant_net brut.
        $this->assertSame(2500.0, $part->montant_a_payer);
        $this->assertSame(2500.0, $part->montant_restant);
    }
}
