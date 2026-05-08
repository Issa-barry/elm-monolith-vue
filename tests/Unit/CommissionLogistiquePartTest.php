<?php

namespace Tests\Unit;

use App\Enums\StatutCommission;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionLogistiquePartTest extends TestCase
{
    use RefreshDatabase;

    // ── montant_restant (accessor) ────────────────────────────────────────────

    public function test_montant_restant_calcule_correctement(): void
    {
        $part = $this->makePart(['montant_net' => 5000, 'montant_verse' => 1500]);

        $this->assertEquals(3500.0, $part->montant_restant);
    }

    public function test_montant_restant_ne_peut_pas_etre_negatif(): void
    {
        $part = $this->makePart(['montant_net' => 1000, 'montant_verse' => 1500]);

        $this->assertEquals(0.0, $part->montant_restant);
    }

    // ── isPaye / isImpaye ─────────────────────────────────────────────────────

    public function test_is_impaye_retourne_true_pour_statut_impaye(): void
    {
        $part = $this->makePart(['statut' => StatutCommission::IMPAYE]);

        $this->assertTrue($part->isImpaye());
        $this->assertFalse($part->isPaye());
    }

    public function test_is_paye_retourne_true_pour_statut_paye(): void
    {
        $part = $this->makePart(['statut' => StatutCommission::PAYE]);

        $this->assertTrue($part->isPaye());
        $this->assertFalse($part->isImpaye());
    }

    public function test_is_paye_retourne_false_pour_statut_partiel(): void
    {
        $part = $this->makePart(['statut' => StatutCommission::PARTIEL]);

        $this->assertFalse($part->isPaye());
        $this->assertFalse($part->isImpaye());
    }

    // ── recalculStatut ────────────────────────────────────────────────────────

    public function test_recalcul_statut_passe_a_paye_si_totalement_verse(): void
    {
        $part = $this->makePart([
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => now()->subDays(15)->toDateString(),
        ]);

        // Simuler paiement via item
        $part->paymentItems()->create(['payment_id' => $this->makePayment($part)->id, 'amount_allocated' => 3000]);

        $part->recalculStatut();

        $this->assertEquals(StatutCommission::PAYE, $part->fresh()->statut);
        $this->assertEquals(3000.0, (float) $part->fresh()->montant_verse);
    }

    public function test_recalcul_statut_passe_a_partiel_si_partiellement_verse(): void
    {
        $part = $this->makePart([
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => now()->subDays(15)->toDateString(),
        ]);

        $part->paymentItems()->create(['payment_id' => $this->makePayment($part)->id, 'amount_allocated' => 1000]);

        $part->recalculStatut();

        $this->assertEquals(StatutCommission::PARTIEL, $part->fresh()->statut);
        $this->assertEquals(1000.0, (float) $part->fresh()->montant_verse);
    }

    public function test_recalcul_statut_reste_impaye_si_rien_verse(): void
    {
        $part = $this->makePart([
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);

        $part->recalculStatut();

        $this->assertEquals(StatutCommission::IMPAYE, $part->fresh()->statut);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makePart(array $overrides = []): CommissionLogistiquePart
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);

        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $this->makeTransfert($org, $vehicule)->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 5000,
            'montant_total' => 5000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        return CommissionLogistiquePart::create(array_merge([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'beneficiaire_nom' => 'Test Livreur',
            'taux_commission' => 100,
            'montant_brut' => 5000,
            'frais_supplementaires' => 0,
            'montant_net' => 5000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => now()->toDateString(),
        ], $overrides));
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule): TransfertLogistique
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Test',
        ]);

        return TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'cloture',
            'created_by' => $user->id,
        ]);
    }

    private function makePayment(CommissionLogistiquePart $part): \App\Models\CommissionPayment
    {
        $vehicule = $part->commission->vehicule;
        $user = User::factory()->create(['organization_id' => $vehicule->organization_id]);

        return \App\Models\CommissionPayment::create([
            'organization_id' => $vehicule->organization_id,
            'vehicule_id' => $vehicule->id,
            'livreur_id' => null,
            'proprietaire_id' => null,
            'beneficiary_type' => $part->type_beneficiaire,
            'beneficiary_nom' => $part->beneficiaire_nom,
            'montant' => $part->montant_net,
            'mode_paiement' => 'especes',
            'paid_at' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
    }
}
