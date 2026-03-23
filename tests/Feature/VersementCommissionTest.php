<?php

namespace Tests\Feature;

use App\Enums\StatutCommission;
use App\Models\CommissionVente;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VersementCommissionTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(Organization $org): User
    {
        Permission::firstOrCreate(['name' => 'ventes.update', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->givePermissionTo('ventes.update');
        return $user;
    }

    private function commissionPourOrg(Organization $org): CommissionVente
    {
        return CommissionVente::factory()->create([
            'organization_id'              => $org->id,
            'montant_commission'           => 5000,
            'montant_part_livreur'         => 3000,
            'montant_part_proprietaire'    => 2000,
            'montant_verse'                => 0,
            'montant_verse_livreur'        => 0,
            'montant_verse_proprietaire'   => 0,
            'statut'                       => StatutCommission::EN_ATTENTE,
        ]);
    }

    // ── Store ──────────────────────────────────────────────────────────────────

    public function test_versement_livreur_seul_est_enregistre(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);

        $response = $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 3000,
                'montant_proprietaire' => 0,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'especes',
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('versements_commissions', [
            'commission_vente_id' => $commission->id,
            'montant'             => 3000,
            'beneficiaire'        => 'livreur',
        ]);
        $this->assertEquals(3000.0, (float) $commission->fresh()->montant_verse_livreur);
        $this->assertEquals(0.0,    (float) $commission->fresh()->montant_verse_proprietaire);
    }

    public function test_versement_proprietaire_seul_est_enregistre(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);

        $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 0,
                'montant_proprietaire' => 2000,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'virement',
            ]
        );

        $this->assertDatabaseHas('versements_commissions', [
            'commission_vente_id' => $commission->id,
            'montant'             => 2000,
            'beneficiaire'        => 'proprietaire',
        ]);
        $this->assertEquals(0.0,    (float) $commission->fresh()->montant_verse_livreur);
        $this->assertEquals(2000.0, (float) $commission->fresh()->montant_verse_proprietaire);
    }

    public function test_versement_les_deux_cree_deux_enregistrements(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);

        $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 3000,
                'montant_proprietaire' => 2000,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'especes',
            ]
        );

        $this->assertDatabaseCount('versements_commissions', 2);

        $fresh = $commission->fresh();
        $this->assertEquals(StatutCommission::VERSEE, $fresh->statut);
        $this->assertEquals(5000.0, (float) $fresh->montant_verse);
    }

    public function test_versement_partiel_met_statut_partielle(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);

        $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 1500,
                'montant_proprietaire' => 0,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'especes',
            ]
        );

        $this->assertEquals(StatutCommission::PARTIELLE, $commission->fresh()->statut);
    }

    public function test_versement_rejete_si_montant_depasse_restant(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);

        $response = $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 9999, // dépasse 3000
                'montant_proprietaire' => 0,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'especes',
            ]
        );

        $response->assertSessionHasErrors('montant_livreur');
        $this->assertDatabaseCount('versements_commissions', 0);
    }

    public function test_versement_rejete_si_les_deux_montants_sont_zero(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);

        $response = $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 0,
                'montant_proprietaire' => 0,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'especes',
            ]
        );

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('versements_commissions', 0);
    }

    public function test_versement_refuse_si_commission_annulee(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);
        $commission->update(['statut' => StatutCommission::ANNULEE]);

        $response = $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 1000,
                'montant_proprietaire' => 0,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'especes',
            ]
        );

        $response->assertStatus(422);
    }

    public function test_versement_refuse_si_autre_organisation(): void
    {
        $autreOrg   = Organization::factory()->create();
        $commission = $this->commissionPourOrg($autreOrg);

        $monOrg = Organization::factory()->create();
        $user   = $this->utilisateur($monOrg);

        $response = $this->actingAs($user)->post(
            route('commissions.versements.store', $commission),
            [
                'montant_livreur'      => 1000,
                'montant_proprietaire' => 0,
                'date_versement'       => now()->toDateString(),
                'mode_paiement'        => 'especes',
            ]
        );

        $response->assertStatus(403);
    }

    // ── Destroy ────────────────────────────────────────────────────────────────

    public function test_versement_supprime_recalcule_statut(): void
    {
        $org        = Organization::factory()->create();
        $user       = $this->utilisateur($org);
        $commission = $this->commissionPourOrg($org);

        $versement = $commission->versements()->create([
            'montant'        => 3000,
            'beneficiaire'   => 'livreur',
            'date_versement' => now()->toDateString(),
            'mode_paiement'  => 'especes',
        ]);
        $commission->recalculStatut();

        $this->actingAs($user)->delete(
            route('commissions.versements.destroy', $versement)
        );

        $fresh = $commission->fresh();
        $this->assertEquals(0.0, (float) $fresh->montant_verse_livreur);
        $this->assertEquals(StatutCommission::EN_ATTENTE, $fresh->statut);
    }
}
