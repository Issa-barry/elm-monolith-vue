<?php

namespace Tests\Feature;

use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\PeriodePaiementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VersementCommissionLogistiqueTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(Organization $org): User
    {
        Permission::firstOrCreate(['name' => 'logistique.commission.verser', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('logistique.commission.verser');

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function validerPeriode(Organization $org, $date, StatutPeriodePaiement $statut): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($org->id, TypePeriodePaiement::LIVREUR, Carbon::parse($date));
        $periode->update(['statut' => $statut]);

        return $periode->fresh();
    }

    /** @return array{org: Organization, user: User, part: CommissionLogistiquePart} */
    private function creerContexte(?StatutPeriodePaiement $statutPeriode = StatutPeriodePaiement::VALIDEE, ?string $earnedAt = null): array
    {
        $org = Organization::factory()->create();
        $user = $this->utilisateur($org);
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Test',
        ]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'reception',
            'created_by' => $user->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 3000,
            'montant_total' => 3000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $earnedAt ??= now()->subDays(15)->toDateString();

        $part = CommissionLogistiquePart::create([
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
            'earned_at' => $earnedAt,
        ]);

        if ($statutPeriode !== null) {
            $this->validerPeriode($org, $earnedAt, $statutPeriode);
        }

        return compact('org', 'user', 'part');
    }

    public function test_versement_autorise_si_periode_validee(): void
    {
        ['user' => $user, 'part' => $part] = $this->creerContexte(StatutPeriodePaiement::VALIDEE);

        $response = $this->actingAs($user)->post(
            route('logistique.commission.versements.store', $part),
            ['montant' => 1000, 'mode_paiement' => 'especes']
        );

        $response->assertRedirect();
        $this->assertEquals(1000.0, (float) $part->fresh()->montant_verse);
    }

    public function test_versement_refuse_si_periode_non_validee(): void
    {
        ['user' => $user, 'part' => $part] = $this->creerContexte(StatutPeriodePaiement::CALCULEE);

        $response = $this->actingAs($user)->post(
            route('logistique.commission.versements.store', $part),
            ['montant' => 1000, 'mode_paiement' => 'especes']
        );

        $response->assertStatus(422);
        $this->assertEquals(0.0, (float) $part->fresh()->montant_verse);
    }

    public function test_versement_refuse_si_aucune_periode_calculee(): void
    {
        ['user' => $user, 'part' => $part] = $this->creerContexte(null);

        $response = $this->actingAs($user)->post(
            route('logistique.commission.versements.store', $part),
            ['montant' => 1000, 'mode_paiement' => 'especes']
        );

        $response->assertStatus(422);
        $this->assertEquals(0.0, (float) $part->fresh()->montant_verse);
    }
}
