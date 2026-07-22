<?php

namespace Tests\Unit;

use App\Enums\StatutPeriodePaiement;
use App\Enums\StatutValidationEquipe;
use App\Enums\TypePeriodePaiement;
use App\Models\Organization;
use App\Models\PaiementPeriode;
use App\Services\CommissionStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre `CommissionStatusResolver::resolve()`, qui remplace l'ancienne logique
 * `statut_effectif` fusionnée : les trois axes (période / équipe / paiement) restent
 * distincts en sortie, `display_status`/`display_label`/`can_pay` n'étant qu'une
 * projection pour le badge principal.
 */
class CommissionStatusResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makePeriode(StatutPeriodePaiement $statut): PaiementPeriode
    {
        $org = Organization::factory()->create();

        return PaiementPeriode::create([
            'organization_id' => $org->id,
            'reference' => 'PAY-'.uniqid(),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => $statut->value,
        ]);
    }

    public function test_commission_annulee_prime_sur_tout_le_reste(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::VALIDEE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::A_VERIFIER, 'annulee', 'Annulée');

        $this->assertSame('annulee', $result['commission_status']);
        $this->assertSame('annulee', $result['display_status']);
        $this->assertSame('Annulée', $result['display_label']);
        $this->assertFalse($result['can_pay']);
    }

    public function test_deja_paye_prime_sur_le_reste(): void
    {
        $result = CommissionStatusResolver::resolve(null, null, 'paye', 'Payé');

        $this->assertSame('validee', $result['commission_status']); // sémantiquement "traitement terminé"
        $this->assertSame('paye', $result['display_status']);
        $this->assertFalse($result['can_pay']);
    }

    public function test_aucune_periode_est_creee_non_payable(): void
    {
        $result = CommissionStatusResolver::resolve(null, null, 'impaye', 'Impayé');

        $this->assertSame('creee', $result['commission_status']);
        $this->assertSame('creee', $result['display_status']);
        $this->assertSame('Créée', $result['display_label']);
        $this->assertFalse($result['can_pay']);
        $this->assertNull($result['periode_status']);
        $this->assertNull($result['team_validation_status']);
    }

    public function test_periode_calculee_equipe_non_validee(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::CALCULEE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::A_VERIFIER, 'impaye', 'Impayé');

        $this->assertSame('en_attente_validation', $result['commission_status']);
        $this->assertSame('en_attente', $result['display_status']);
        $this->assertSame('En attente de validation', $result['display_label']);
        $this->assertFalse($result['can_pay']);
        $this->assertSame('a_verifier', $result['team_validation_status']);
    }

    public function test_periode_calculee_equipe_deja_validee_donne_un_libelle_distinct(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::CALCULEE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::VALIDEE, 'impaye', 'Impayé');

        $this->assertSame('en_attente_validation', $result['commission_status']);
        $this->assertSame('repartition_validee', $result['display_status']);
        $this->assertSame('Répartition validée — période en attente', $result['display_label']);
        $this->assertFalse($result['can_pay']);
    }

    public function test_periode_brouillon_reste_en_attente_meme_equipe_validee(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::PAYEE, 'impaye', 'Impayé');

        $this->assertFalse($result['can_pay']);
        $this->assertSame('repartition_validee', $result['display_status']);
    }

    public function test_periode_validee_impaye_est_payable(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::VALIDEE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::VALIDEE, 'impaye', 'Impayé');

        $this->assertSame('validee', $result['commission_status']);
        $this->assertSame('impaye', $result['display_status']);
        $this->assertSame('Impayé', $result['display_label']);
        $this->assertTrue($result['can_pay']);
    }

    public function test_periode_validee_partiel_est_payable(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::VALIDEE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::VALIDEE, 'partiel', 'Partiel');

        $this->assertSame('partiel', $result['display_status']);
        $this->assertTrue($result['can_pay']);
    }

    public function test_periode_validee_paye_nest_plus_payable(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::VALIDEE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::PAYEE, 'paye', 'Payé');

        $this->assertSame('paye', $result['display_status']);
        $this->assertFalse($result['can_pay']);
    }

    public function test_periode_cloturee_avec_reste_signale_cloturee(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::CLOTUREE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::VALIDEE, 'impaye', 'Impayé');

        $this->assertSame('cloturee', $result['display_status']);
        $this->assertSame('Clôturée', $result['display_label']);
        $this->assertFalse($result['can_pay']);
    }

    public function test_periode_cloturee_soldee_affiche_paye(): void
    {
        $periode = $this->makePeriode(StatutPeriodePaiement::CLOTUREE);

        $result = CommissionStatusResolver::resolve($periode, StatutValidationEquipe::PAYEE, 'paye', 'Payé');

        $this->assertSame('paye', $result['display_status']);
        $this->assertFalse($result['can_pay']);
    }
}
