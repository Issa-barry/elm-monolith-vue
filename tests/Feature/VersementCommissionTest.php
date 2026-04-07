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
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('ventes.update');

        // Site requis par RequireSiteAssigned
        $site = \App\Models\Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /**
     * Crée une CommissionVente avec une part livreur (3 000) et une part propriétaire (2 000).
     */
    private function makeCommissionAvecParts(Organization $org): array
    {
        $commission = CommissionVente::factory()->create([
            'organization_id' => $org->id,
            'montant_commission_totale' => 5000,
            'montant_verse' => 0,
            'statut' => StatutCommission::EN_ATTENTE,
        ]);

        $partLivreur = $commission->parts()->create([
            'type_beneficiaire' => 'livreur',
            'beneficiaire_nom' => 'Diallo Mamadou',
            'taux_commission' => 60,
            'montant_brut' => 3000,
            'frais_supplementaires' => 0,
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::EN_ATTENTE,
        ]);

        $partProp = $commission->parts()->create([
            'type_beneficiaire' => 'proprietaire',
            'beneficiaire_nom' => 'Camara Ibrahim',
            'taux_commission' => 40,
            'montant_brut' => 2000,
            'frais_supplementaires' => 0,
            'montant_net' => 2000,
            'montant_verse' => 0,
            'statut' => StatutCommission::EN_ATTENTE,
        ]);

        return compact('commission', 'partLivreur', 'partProp');
    }

    // ── Store ──────────────────────────────────────────────────────────────────

    public function test_versement_sur_part_livreur_est_enregistre(): void
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        ['commission' => $commission, 'partLivreur' => $part] = $this->makeCommissionAvecParts($org);

        $response = $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $part]),
            [
                'montant' => 3000,
                'date_versement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('versements_commissions', [
            'commission_part_id' => $part->id,
            'montant' => 3000,
        ]);

        $this->assertEquals(3000.0, (float) $part->fresh()->montant_verse);
        $this->assertEquals(StatutCommission::VERSEE, $part->fresh()->statut);
    }

    public function test_versement_partiel_met_statut_partielle(): void
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        ['commission' => $commission, 'partLivreur' => $part] = $this->makeCommissionAvecParts($org);

        $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $part]),
            [
                'montant' => 1500,
                'date_versement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $this->assertEquals(StatutCommission::PARTIELLE, $part->fresh()->statut);
        $this->assertEquals(StatutCommission::PARTIELLE, $commission->fresh()->statut);
    }

    public function test_versement_deux_parts_soldes_met_commission_versee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        ['commission' => $commission, 'partLivreur' => $partL, 'partProp' => $partP] = $this->makeCommissionAvecParts($org);

        $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $partL]),
            ['montant' => 3000, 'date_versement' => now()->toDateString(), 'mode_paiement' => 'especes']
        );
        $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $partP]),
            ['montant' => 2000, 'date_versement' => now()->toDateString(), 'mode_paiement' => 'virement']
        );

        $this->assertDatabaseCount('versements_commissions', 2);
        $this->assertEquals(StatutCommission::VERSEE, $commission->fresh()->statut);
        $this->assertEquals(5000.0, (float) $commission->fresh()->montant_verse);
    }

    public function test_versement_rejete_si_montant_depasse_restant(): void
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        ['commission' => $commission, 'partLivreur' => $part] = $this->makeCommissionAvecParts($org);

        $response = $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $part]),
            [
                'montant' => 9999, // dépasse 3000
                'date_versement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertSessionHasErrors('montant');
        $this->assertDatabaseCount('versements_commissions', 0);
    }

    public function test_versement_refuse_si_commission_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        ['commission' => $commission, 'partLivreur' => $part] = $this->makeCommissionAvecParts($org);
        $commission->update(['statut' => StatutCommission::ANNULEE]);

        $response = $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $part]),
            [
                'montant' => 1000,
                'date_versement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertStatus(422);
    }

    public function test_versement_refuse_si_part_deja_versee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        ['commission' => $commission, 'partLivreur' => $part] = $this->makeCommissionAvecParts($org);
        $part->update(['statut' => StatutCommission::VERSEE, 'montant_verse' => 3000]);

        $response = $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $part]),
            [
                'montant' => 100,
                'date_versement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertStatus(422);
    }

    public function test_versement_refuse_si_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        ['commission' => $commission, 'partLivreur' => $part] = $this->makeCommissionAvecParts($autreOrg);

        $monOrg = Organization::factory()->create();
        $user = $this->utilisateur($monOrg);

        $response = $this->actingAs($user)->post(
            route('commissions.parts.versements.store', [$commission, $part]),
            [
                'montant' => 1000,
                'date_versement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertStatus(403);
    }

    // ── Destroy ────────────────────────────────────────────────────────────────

    public function test_versement_supprime_recalcule_statut_part(): void
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        ['commission' => $commission, 'partLivreur' => $part] = $this->makeCommissionAvecParts($org);

        $versement = $part->versements()->create([
            'montant' => 3000,
            'date_versement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
        $part->recalculStatut();

        $this->assertEquals(StatutCommission::VERSEE, $part->fresh()->statut);

        $this->actingAs($user)->delete(
            route('commissions.versements.destroy', $versement)
        );

        $this->assertEquals(StatutCommission::EN_ATTENTE, $part->fresh()->statut);
        $this->assertEquals(0.0, (float) $part->fresh()->montant_verse);
    }
}
