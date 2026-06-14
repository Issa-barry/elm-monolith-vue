<?php

namespace Tests\Unit;

use App\Enums\StatutCommission;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommissionPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── payer() : validations ─────────────────────────────────────────────────

    public function test_payer_leve_exception_si_montant_zero(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur] = $this->makeScenario();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/montant doit être supérieur/i');

        $this->actingAs($this->makeUser($vehicule->organization));
        CommissionPaymentService::payer($vehicule, 'livreur', $livreur->id, 0, 'especes', now()->toDateString());
    }

    public function test_payer_leve_exception_si_montant_depasse_solde(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur] = $this->makeScenario(montantNet: 2000);

        $this->actingAs($this->makeUser($vehicule->organization));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dépasse le solde disponible/i');

        CommissionPaymentService::payer($vehicule, 'livreur', $livreur->id, 5000, 'especes', now()->toDateString());
    }

    // ── payer() : paiement simple ─────────────────────────────────────────────

    public function test_payer_cree_payment_et_item_et_met_a_jour_statut(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'part' => $part] = $this->makeScenario(montantNet: 3000);

        $this->actingAs($this->makeUser($vehicule->organization));
        $payment = CommissionPaymentService::payer($vehicule, 'livreur', $livreur->id, 3000, 'especes', now()->toDateString());

        $this->assertDatabaseHas('commission_payments', [
            'vehicule_id' => $vehicule->id,
            'livreur_id' => $livreur->id,
            'beneficiary_type' => 'livreur',
            'montant' => 3000,
            'mode_paiement' => 'especes',
        ]);

        $this->assertDatabaseHas('commission_payment_items', [
            'payment_id' => $payment->id,
            'part_id' => $part->id,
            'amount_allocated' => 3000,
        ]);

        $this->assertEquals(StatutCommission::PAYE, $part->fresh()->statut);
    }

    public function test_payer_partiel_passe_statut_a_partiel(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'part' => $part] = $this->makeScenario(montantNet: 3000);

        $this->actingAs($this->makeUser($vehicule->organization));
        CommissionPaymentService::payer($vehicule, 'livreur', $livreur->id, 1000, 'especes', now()->toDateString());

        $this->assertEquals(StatutCommission::PARTIEL, $part->fresh()->statut);
        $this->assertEquals(1000.0, (float) $part->fresh()->montant_verse);
    }

    // ── payer() : allocation FIFO multi-parts ─────────────────────────────────

    public function test_payer_alloue_en_fifo_sur_plusieurs_parts(): void
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = $this->makeCommission($org, $vehicule);

        $partAncienne = $this->makePart($commission, $livreur, [
            'montant_net' => 1000,
            'earned_at' => now()->subDays(20)->toDateString(),
        ]);
        $partRecente = $this->makePart($commission, $livreur, [
            'montant_net' => 2000,
            'earned_at' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($this->makeUser($org));
        CommissionPaymentService::payer($vehicule, 'livreur', $livreur->id, 1500, 'especes', now()->toDateString());

        $this->assertEquals(StatutCommission::PAYE, $partAncienne->fresh()->statut);
        $this->assertEquals(1000.0, (float) $partAncienne->fresh()->montant_verse);

        $this->assertEquals(StatutCommission::PARTIEL, $partRecente->fresh()->statut);
        $this->assertEquals(500.0, (float) $partRecente->fresh()->montant_verse);
    }

    public function test_payer_peut_solder_plusieurs_parts_en_une_fois(): void
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $commission = $this->makeCommission($org, $vehicule);

        $part1 = $this->makePart($commission, $livreur, ['montant_net' => 1000, 'earned_at' => now()->subDays(20)->toDateString()]);
        $part2 = $this->makePart($commission, $livreur, ['montant_net' => 2000, 'earned_at' => now()->subDays(10)->toDateString()]);
        $part3 = $this->makePart($commission, $livreur, ['montant_net' => 500,  'earned_at' => now()->subDays(5)->toDateString()]);

        $this->actingAs($this->makeUser($org));
        CommissionPaymentService::payer($vehicule, 'livreur', $livreur->id, 3500, 'especes', now()->toDateString());

        $this->assertEquals(StatutCommission::PAYE, $part1->fresh()->statut);
        $this->assertEquals(StatutCommission::PAYE, $part2->fresh()->statut);
        $this->assertEquals(StatutCommission::PAYE, $part3->fresh()->statut);
    }

    // ── partsDisponibles ──────────────────────────────────────────────────────

    public function test_parts_disponibles_exclut_parts_payees(): void
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $commission = $this->makeCommission($org, $vehicule);

        $this->makePart($commission, $livreur, [
            'statut' => StatutCommission::PAYE,
            'montant_verse' => 3000,
        ]);

        $parts = CommissionPaymentService::partsDisponibles($vehicule, 'livreur', $livreur->id);

        $this->assertCount(0, $parts);
    }

    public function test_parts_disponibles_inclut_impaye_et_partiel(): void
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $commission = $this->makeCommission($org, $vehicule);

        $this->makePart($commission, $livreur, ['statut' => StatutCommission::IMPAYE]);
        $this->makePart($commission, $livreur, ['statut' => StatutCommission::PARTIEL, 'montant_verse' => 500]);
        $this->makePart($commission, $livreur, ['statut' => StatutCommission::PAYE, 'montant_verse' => 3000]);

        $parts = CommissionPaymentService::partsDisponibles($vehicule, 'livreur', $livreur->id);

        $this->assertCount(2, $parts);
    }

    // ── soldesParVehicule ─────────────────────────────────────────────────────

    public function test_soldes_par_vehicule_agrege_correctement(): void
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $commission = $this->makeCommission($org, $vehicule);

        $this->makePart($commission, $livreur, ['statut' => StatutCommission::IMPAYE,  'montant_net' => 1000, 'montant_verse' => 0]);
        $this->makePart($commission, $livreur, ['statut' => StatutCommission::PARTIEL, 'montant_net' => 2000, 'montant_verse' => 500]);
        $this->makePart($commission, $livreur, ['statut' => StatutCommission::PAYE,    'montant_net' => 600,  'montant_verse' => 600]);

        $soldes = CommissionPaymentService::soldesParVehicule($vehicule);

        $this->assertCount(1, $soldes['livreurs']);
        $livreurRow = $soldes['livreurs'][0];

        // impaye = restant sur parts IMPAYE + PARTIEL : 1000 + (2000-500) = 2500
        $this->assertEquals(2500.0, (float) $livreurRow['impaye']);
        // paye = SUM(montant_verse) toutes parts : 0 + 500 + 600 = 1100
        $this->assertEquals(1100.0, (float) $livreurRow['paye']);
    }

    public function test_soldes_par_vehicule_sans_parts_retourne_vide(): void
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);

        $soldes = CommissionPaymentService::soldesParVehicule($vehicule);

        $this->assertCount(0, $soldes['livreurs']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeScenario(float $montantNet = 3000): array
    {
        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $commission = $this->makeCommission($org, $vehicule);

        $part = $this->makePart($commission, $livreur, ['montant_net' => $montantNet]);

        return compact('org', 'vehicule', 'livreur', 'commission', 'part');
    }

    private function makeCommission(Organization $org, Vehicule $vehicule): CommissionLogistique
    {
        return CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $this->makeTransfert($org, $vehicule)->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 5000,
            'montant_total' => 5000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule): TransfertLogistique
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
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

    private function makePart(
        CommissionLogistique $commission,
        Livreur $livreur,
        array $overrides = []
    ): CommissionLogistiquePart {
        return CommissionLogistiquePart::create(array_merge([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->prenom.' '.$livreur->nom,
            'taux_commission' => 60,
            'montant_brut' => 3000,
            'frais_supplementaires' => 0,
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => now()->subDays(15)->toDateString(),
        ], $overrides));
    }

    private function makeUser(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }
}
