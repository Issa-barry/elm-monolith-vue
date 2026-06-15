<?php

namespace Tests\Feature;

use App\Enums\StatutCommission;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementCommissionVente;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaiementCommissionVenteTest extends TestCase
{
    use RefreshDatabase;

    private function creerContexte(): array
    {
        $org = Organization::factory()->create();

        Permission::firstOrCreate(['name' => 'ventes.read', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('ventes.read');

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = CommissionVente::factory()->create([
            'organization_id' => $org->id,
            'montant_commission_totale' => 5000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);

        $commission->parts()->create([
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom,
            'taux_commission' => 100,
            'montant_brut' => 5000,
            'frais_supplementaires' => 0,
            'montant_net' => 5000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);

        return compact('org', 'user', 'livreur', 'commission');
    }

    public function test_paiement_commission_vente_enregistre_avec_paid_at(): void
    {
        ['user' => $user, 'livreur' => $livreur] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('commissions.beneficiaires.paiements.store', ['type' => 'livreur', 'beneficiaireId' => $livreur->id]),
            [
                'montant' => 3000,
                'mode_paiement' => 'especes',
                'paid_at' => '2026-01-15',
            ]
        )->assertRedirect();

        $paiement = PaiementCommissionVente::where('livreur_id', $livreur->id)->first();
        $this->assertNotNull($paiement);
        $this->assertEquals(3000, (float) $paiement->montant);
        $this->assertEquals('2026-01-15', $paiement->paid_at->toDateString());
    }

    public function test_paiement_commission_vente_sans_paid_at_utilise_date_du_jour(): void
    {
        ['user' => $user, 'livreur' => $livreur] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('commissions.beneficiaires.paiements.store', ['type' => 'livreur', 'beneficiaireId' => $livreur->id]),
            [
                'montant' => 2000,
                'mode_paiement' => 'virement',
                // paid_at absent : doit defaulter à today()
            ]
        )->assertRedirect();

        $paiement = PaiementCommissionVente::where('livreur_id', $livreur->id)->first();
        $this->assertNotNull($paiement);
        $this->assertEquals(2000, (float) $paiement->montant);
        $this->assertEquals(now()->toDateString(), $paiement->paid_at->toDateString());
    }

    public function test_paiement_commission_vente_refuse_sans_permission(): void
    {
        $org = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $this->actingAs($user)->post(
            route('commissions.beneficiaires.paiements.store', ['type' => 'livreur', 'beneficiaireId' => 'some-id']),
            ['montant' => 100, 'mode_paiement' => 'especes']
        )->assertStatus(403);
    }
}
