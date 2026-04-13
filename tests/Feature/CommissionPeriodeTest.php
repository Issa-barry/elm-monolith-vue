<?php

namespace Tests\Feature;

use App\Enums\StatutPartCommission;
use App\Features\ModuleFeature;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use App\Services\PeriodeComptableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests d'intégration : classification par période et comportement du paiement.
 */
class CommissionPeriodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::LOGISTIQUE);

        return $org;
    }

    private function makeUser(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);

        $permissions = ['logistique.read', 'logistique.commission.verser'];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);

        $site = Site::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Site Test Période'],
            ['type' => 'depot', 'localisation' => 'Conakry']
        );
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makePart(
        Organization $org,
        Livreur $livreur,
        string $earnedAt,
        float $montantNet = 10000.0
    ): CommissionLogistiquePart {
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);

        $sysUser = User::factory()->create(['organization_id' => $org->id]);

        $site = Site::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Dépôt Test'],
            ['type' => 'depot', 'localisation' => 'Conakry']
        );

        $transfert = TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'reception',
            'date_arrivee_reelle' => $earnedAt,
            'created_by' => $sysUser->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => $montantNet,
            'montant_total' => $montantNet,
            'montant_verse' => 0,
            'statut' => 'en_attente',
        ]);

        $periode = PeriodeComptableService::codeForLivreur(Carbon::parse($earnedAt));

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'taux_commission' => 100,
            'montant_brut' => $montantNet,
            'frais_supplementaires' => 0,
            'montant_net' => $montantNet,
            'montant_verse' => 0,
            'statut' => StatutPartCommission::AVAILABLE,
            'earned_at' => $earnedAt,
            'unlock_at' => $earnedAt,
            'periode' => $periode,
        ]);
    }

    // ── Tests classification période ─────────────────────────────────────────

    /** @test */
    public function part_created_on_day_12_gets_p1(): void
    {
        $org = $this->makeOrg();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $part = $this->makePart($org, $livreur, '2026-04-12');

        $this->assertSame('2026-04-P1', $part->periode);
    }

    /** @test */
    public function part_created_on_day_26_gets_p2(): void
    {
        $org = $this->makeOrg();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $part = $this->makePart($org, $livreur, '2026-04-26');

        $this->assertSame('2026-04-P2', $part->periode);
    }

    /** @test */
    public function part_created_on_day_15_gets_p1(): void
    {
        $org = $this->makeOrg();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $part = $this->makePart($org, $livreur, '2026-04-15');

        $this->assertSame('2026-04-P1', $part->periode);
    }

    /** @test */
    public function part_created_on_day_16_gets_p2(): void
    {
        $org = $this->makeOrg();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $part = $this->makePart($org, $livreur, '2026-04-16');

        $this->assertSame('2026-04-P2', $part->periode);
    }

    // ── Tests : la période ne change pas après paiement ───────────────────────

    /** @test */
    public function late_payment_does_not_reclassify_period(): void
    {
        // Commission du 26/04 payée le 02/05 → reste 2026-04-P2
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $part = $this->makePart($org, $livreur, '2026-04-26');
        $this->assertSame('2026-04-P2', $part->periode);

        // Simuler un paiement le 02/05 (paiement en retard)
        $this->actingAs($user);
        CommissionPaymentService::payerLivreur(
            $livreur->id,
            $org->id,
            (float) $part->montant_net,
            'especes',
            '2026-05-02',
        );

        $part->refresh();

        // La période NE doit PAS changer
        $this->assertSame('2026-04-P2', $part->periode);
        $this->assertSame(StatutPartCommission::PAID, $part->statut);
    }

    /** @test */
    public function advance_payment_does_not_reclassify_period(): void
    {
        // Commission du 14/04 payée le 10/04 (avance) → reste 2026-04-P1
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $part = $this->makePart($org, $livreur, '2026-04-14');
        $this->assertSame('2026-04-P1', $part->periode);

        // Simuler un paiement anticipé le 10/04
        $this->actingAs($user);
        CommissionPaymentService::payerLivreur(
            $livreur->id,
            $org->id,
            (float) $part->montant_net,
            'especes',
            '2026-04-10',
        );

        $part->refresh();

        $this->assertSame('2026-04-P1', $part->periode);
        $this->assertSame(StatutPartCommission::PAID, $part->statut);
    }

    // ── Tests : filtre période dans le contrôleur ─────────────────────────────

    /** @test */
    public function show_livreur_filters_parts_by_periode(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->makePart($org, $livreur, '2026-04-12'); // P1
        $this->makePart($org, $livreur, '2026-04-26'); // P2

        $this->actingAs($user)
            ->get("/logistique/commissions/livreurs/{$livreur->id}?periode=2026-04-P1")
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Logistique/Commissions/Livreur/Show')
                    ->where('selected_periode', '2026-04-P1')
                    ->has('parts', 1)
                    ->where('parts.0.periode', '2026-04-P1')
            );
    }

    /** @test */
    public function show_livreur_returns_all_parts_without_filter(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->makePart($org, $livreur, '2026-04-12');
        $this->makePart($org, $livreur, '2026-04-26');

        $this->actingAs($user)
            ->get("/logistique/commissions/livreurs/{$livreur->id}")
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Logistique/Commissions/Livreur/Show')
                    ->where('selected_periode', '')
                    ->has('parts', 2)
            );
    }

    /** @test */
    public function show_livreur_passes_periode_courante_and_disponibles(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->makePart($org, $livreur, '2026-04-12');

        $this->actingAs($user)
            ->get("/logistique/commissions/livreurs/{$livreur->id}")
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Logistique/Commissions/Livreur/Show')
                    ->has('periode_courante')
                    ->has('periode_courante_label')
                    ->has('periodes_disponibles')
            );
    }

    /** @test */
    public function parts_include_periode_and_periode_label(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $this->makePart($org, $livreur, '2026-04-12');

        $this->actingAs($user)
            ->get("/logistique/commissions/livreurs/{$livreur->id}")
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Logistique/Commissions/Livreur/Show')
                    ->has('parts', 1)
                    ->where('parts.0.periode', '2026-04-P1')
                    ->where('parts.0.periode_label', fn ($v) => str_contains($v, 'P1'))
            );
    }
}
