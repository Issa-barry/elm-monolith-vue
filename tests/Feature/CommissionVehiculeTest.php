<?php

namespace Tests\Feature;

use App\Enums\StatutPartCommission;
use App\Features\ModuleFeature;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPaymentItem;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommissionVehiculeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    // ── Setup helpers ─────────────────────────────────────────────────────────

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::LOGISTIQUE);

        return $org;
    }

    private function makeUser(Organization $org, array $extraPermissions = []): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);

        $permissions = array_unique(array_merge(
            ['logistique.read', 'logistique.commission.verser'],
            $extraPermissions
        ));

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);

        $site = Site::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Site Test'],
            ['type' => 'depot', 'localisation' => 'Conakry']
        );
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeVehicule(Organization $org): Vehicule
    {
        return Vehicule::factory()->create(['organization_id' => $org->id]);
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
            'statut' => 'en_attente',
        ]);
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule): TransfertLogistique
    {
        // makeUser already sets up a user for auth; create a minimal one here for created_by
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $sysUser = User::factory()->create(['organization_id' => $org->id]);

        $site = Site::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Dépôt Principal'],
            ['type' => 'depot', 'localisation' => 'Conakry']
        );

        return TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'cloture',
            'created_by' => $sysUser->id,
        ]);
    }

    private function makePart(CommissionLogistique $commission, Livreur $livreur, array $overrides = []): CommissionLogistiquePart
    {
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
            'statut' => StatutPartCommission::AVAILABLE,
            'earned_at' => now()->subDays(15)->toDateString(),
        ], $overrides));
    }

    // ── GET /logistique/commissions ───────────────────────────────────────────

    public function test_index_retourne_200_pour_admin(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);

        $this->actingAs($user)
            ->get('/logistique/commissions')
            ->assertStatus(200);
    }

    public function test_index_redirige_si_non_authentifie(): void
    {
        $this->get('/logistique/commissions')
            ->assertRedirect(route('login'));
    }

    public function test_index_renvoie_les_livreurs_avec_commissions(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $commission = $this->makeCommission($org, $vehicule);
        $this->makePart($commission, $livreur, ['montant_net' => 2000]);

        $this->actingAs($user)
            ->get('/logistique/commissions')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistique/Commissions/Index')
                ->has('livreurs', 1)
                ->where('livreurs.0.livreur_id', $livreur->id)
                ->where('kpis.nb_livreurs', 1)
            );
    }

    public function test_index_naffiche_pas_livreurs_dautres_organisations(): void
    {
        $org1 = $this->makeOrg();
        $org2 = $this->makeOrg();
        $user1 = $this->makeUser($org1);

        $vehicule2 = $this->makeVehicule($org2);
        $livreur2 = Livreur::factory()->create(['organization_id' => $org2->id]);
        $comm2 = $this->makeCommission($org2, $vehicule2);
        $this->makePart($comm2, $livreur2);

        $this->actingAs($user1)
            ->get('/logistique/commissions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('livreurs', 0)
            );
    }

    // ── GET /logistique/commissions/vehicules/{vehicule} ─────────────────────

    public function test_show_vehicule_retourne_200(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);

        $this->actingAs($user)
            ->get("/logistique/commissions/vehicules/{$vehicule->id}")
            ->assertStatus(200);
    }

    public function test_show_vehicule_retourne_403_pour_autre_org(): void
    {
        $org1 = $this->makeOrg();
        $org2 = $this->makeOrg();
        $user = $this->makeUser($org1);
        $vehicule2 = $this->makeVehicule($org2);

        $this->actingAs($user)
            ->get("/logistique/commissions/vehicules/{$vehicule2->id}")
            ->assertStatus(403);
    }

    public function test_show_vehicule_renvoie_soldes_par_beneficiaire(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = $this->makeCommission($org, $vehicule);
        $this->makePart($commission, $livreur, ['montant_net' => 3000]);

        $this->actingAs($user)
            ->get("/logistique/commissions/vehicules/{$vehicule->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistique/Commissions/Vehicule/Show')
                ->has('livreurs', 1)
                ->where('livreurs.0.id', $livreur->id)
                ->where('livreurs.0.available', fn ($v) => (float) $v === 3000.0)
            );
    }

    // ── GET /logistique/commissions/vehicules/{vehicule}/beneficiaires/{type}/{id} ──

    public function test_releve_retourne_200_pour_livreur(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $commission = $this->makeCommission($org, $vehicule);
        $this->makePart($commission, $livreur);

        $this->actingAs($user)
            ->get("/logistique/commissions/vehicules/{$vehicule->id}/beneficiaires/livreur/{$livreur->id}")
            ->assertStatus(200);
    }

    public function test_releve_retourne_422_pour_type_invalide(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);

        $this->actingAs($user)
            ->get("/logistique/commissions/vehicules/{$vehicule->id}/beneficiaires/inconnu/1")
            ->assertStatus(422);
    }

    public function test_releve_retourne_parts_avec_paiements(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = $this->makeCommission($org, $vehicule);
        $part = $this->makePart($commission, $livreur, ['montant_net' => 3000]);

        // Simuler un paiement alloué
        $payment = \App\Models\CommissionPayment::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'livreur_id' => $livreur->id,
            'beneficiary_type' => 'livreur',
            'beneficiary_nom' => $part->beneficiaire_nom,
            'montant' => 1000,
            'mode_paiement' => 'especes',
            'paid_at' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        CommissionPaymentItem::create(['payment_id' => $payment->id, 'part_id' => $part->id, 'amount_allocated' => 1000]);
        $part->recalculStatut();

        $this->actingAs($user)
            ->get("/logistique/commissions/vehicules/{$vehicule->id}/beneficiaires/livreur/{$livreur->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistique/Commissions/Beneficiaire/Show')
                ->has('parts', 1)
                ->has('parts.0.payments', 1)
                ->where('parts.0.statut', StatutPartCommission::PARTIAL->value)
            );
    }

    // ── POST /logistique/commissions/vehicules/{vehicule}/paiements ───────────

    public function test_store_paiement_enregistre_et_redirige(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = $this->makeCommission($org, $vehicule);
        $part = $this->makePart($commission, $livreur, ['montant_net' => 3000]);

        $this->actingAs($user)
            ->post("/logistique/commissions/vehicules/{$vehicule->id}/paiements", [
                'beneficiary_type' => 'livreur',
                'beneficiary_id' => $livreur->id,
                'montant' => 2000,
                'mode_paiement' => 'especes',
                'note' => 'Test paiement',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commission_payments', [
            'vehicule_id' => $vehicule->id,
            'livreur_id' => $livreur->id,
            'montant' => 2000,
            'mode_paiement' => 'especes',
        ]);

        $this->assertDatabaseHas('commission_payment_items', [
            'part_id' => $part->id,
            'amount_allocated' => 2000,
        ]);

        $this->assertEquals(StatutPartCommission::PARTIAL, $part->fresh()->statut);
        $this->assertEquals(2000.0, (float) $part->fresh()->montant_verse);
    }

    public function test_store_paiement_total_passe_statut_a_paid(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = $this->makeCommission($org, $vehicule);
        $part = $this->makePart($commission, $livreur, ['montant_net' => 2500]);

        $this->actingAs($user)
            ->post("/logistique/commissions/vehicules/{$vehicule->id}/paiements", [
                'beneficiary_type' => 'livreur',
                'beneficiary_id' => $livreur->id,
                'montant' => 2500,
                'mode_paiement' => 'virement',
            ]);

        $this->assertEquals(StatutPartCommission::PAID, $part->fresh()->statut);
    }

    public function test_store_paiement_retourne_erreur_si_montant_depasse_solde(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = $this->makeCommission($org, $vehicule);
        $this->makePart($commission, $livreur, ['montant_net' => 1000]);

        $this->actingAs($user)
            ->post("/logistique/commissions/vehicules/{$vehicule->id}/paiements", [
                'beneficiary_type' => 'livreur',
                'beneficiary_id' => $livreur->id,
                'montant' => 9999,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('montant');
    }

    public function test_store_paiement_refusé_sans_authentification(): void
    {
        $org = $this->makeOrg();
        $vehicule = $this->makeVehicule($org);

        $this->post("/logistique/commissions/vehicules/{$vehicule->id}/paiements", [
            'beneficiary_type' => 'livreur',
            'beneficiary_id' => 1,
            'montant' => 1000,
            'mode_paiement' => 'especes',
        ])->assertRedirect(route('login'));
    }

    public function test_store_paiement_valide_champs_requis(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);

        $this->actingAs($user)
            ->post("/logistique/commissions/vehicules/{$vehicule->id}/paiements", [])
            ->assertSessionHasErrors(['beneficiary_type', 'beneficiary_id', 'montant', 'mode_paiement']);
    }

    public function test_store_paiement_refuse_mode_invalide(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);

        $this->actingAs($user)
            ->post("/logistique/commissions/vehicules/{$vehicule->id}/paiements", [
                'beneficiary_type' => 'livreur',
                'beneficiary_id' => 1,
                'montant' => 1000,
                'mode_paiement' => 'bitcoin',
            ])
            ->assertSessionHasErrors('mode_paiement');
    }

    // ── Non-régression : paiements FIFO + traçabilité ─────────────────────────

    public function test_fifo_allocation_via_http_sur_plusieurs_parts(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $vehicule = $this->makeVehicule($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $commission = $this->makeCommission($org, $vehicule);
        $partA = $this->makePart($commission, $livreur, [
            'montant_net' => 1000,
            'earned_at' => now()->subDays(20)->toDateString(),
        ]);
        $partB = $this->makePart($commission, $livreur, [
            'montant_net' => 2000,
            'earned_at' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($user)
            ->post("/logistique/commissions/vehicules/{$vehicule->id}/paiements", [
                'beneficiary_type' => 'livreur',
                'beneficiary_id' => $livreur->id,
                'montant' => 1500,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        // Part la plus ancienne doit être soldée en premier
        $this->assertEquals(StatutPartCommission::PAID, $partA->fresh()->statut);
        $this->assertEquals(1000.0, (float) $partA->fresh()->montant_verse);

        $this->assertEquals(StatutPartCommission::PARTIAL, $partB->fresh()->statut);
        $this->assertEquals(500.0, (float) $partB->fresh()->montant_verse);

        // Un seul paiement doit avoir été créé, avec 2 items
        $this->assertDatabaseCount('commission_payments', 1);
        $this->assertDatabaseCount('commission_payment_items', 2);
    }
}
