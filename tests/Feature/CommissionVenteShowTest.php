<?php

namespace Tests\Feature;

use App\Enums\StatutCommission;
use App\Models\CommissionVente;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionVenteShowTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read']);
    }

    // ── Accès ─────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertStatus(200);
    }

    public function test_show_redirects_unauthenticated_user(): void
    {
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $this->get(route('commissions.show', $commission))
            ->assertRedirect(route('login'));
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $commission = CommissionVente::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertStatus(403);
    }

    public function test_show_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)
            ->get(route('commissions.show', $commission))
            ->assertStatus(403);
    }

    // ── Structure Inertia ─────────────────────────────────────────────────────

    public function test_show_returns_expected_inertia_keys(): void
    {
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertInertia(fn ($page) => $page
                ->component('Commissions/Show')
                ->has('commission')
                ->has('modes_paiement')
                ->has('commission.parts')
            );
    }

    // ── Agrégats : part_livreur_total et part_proprietaire_total ─────────────
    //   Ces valeurs sont calculées côté Vue à partir des parts fournies.
    //   On vérifie ici que le backend renvoie les montant_net corrects par part.

    public function test_show_parts_contiennent_les_montants_nets_livreur(): void
    {
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $commission->parts()->createMany([
            $this->partData('livreur', montant_net: 5_400),
            $this->partData('livreur', montant_net: 4_050),
            $this->partData('livreur', montant_net: 1_350),
        ]);

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertInertia(fn ($page) => $page
                ->has('commission.parts', 3)
                ->where('commission.parts.0.montant_net', 5_400)
                ->where('commission.parts.1.montant_net', 4_050)
                ->where('commission.parts.2.montant_net', 1_350)
            );
    }

    public function test_show_parts_contiennent_les_montants_nets_proprietaire(): void
    {
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $commission->parts()->create(
            $this->partData('proprietaire', montant_brut: 20_000, frais: 2_000, montant_net: 18_000),
        );

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertInertia(fn ($page) => $page
                ->has('commission.parts', 1)
                ->where('commission.parts.0.type_beneficiaire', 'proprietaire')
                ->where('commission.parts.0.montant_brut', 20_000)
                ->where('commission.parts.0.frais_supplementaires', 2_000)
                ->where('commission.parts.0.montant_net', 18_000)
            );
    }

    public function test_show_agrégat_livreur_total_est_somme_des_montants_nets(): void
    {
        // Vérifie que la somme des montant_net livreur = 10 800 GNF
        // (valeur attendue calculée côté Vue : partLivreurTotal)
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $commission->parts()->createMany([
            $this->partData('livreur', montant_net: 5_400),
            $this->partData('livreur', montant_net: 4_050),
            $this->partData('livreur', montant_net: 1_350),
        ]);

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertInertia(fn ($page) => $page
                ->has('commission.parts', 3)
                ->where('commission.parts', fn ($parts) => (
                    (int) collect($parts)
                        ->where('type_beneficiaire', 'livreur')
                        ->sum('montant_net') === 10_800
                ))
            );
    }

    public function test_show_agrégat_proprietaire_total_est_somme_des_montants_nets(): void
    {
        // Vérifie que montant_net proprietaire = 16 200 GNF (brut 18 000 − frais 1 800)
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $commission->parts()->create(
            $this->partData('proprietaire', montant_brut: 18_000, frais: 1_800, montant_net: 16_200),
        );

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertInertia(fn ($page) => $page
                ->where('commission.parts', fn ($parts) => (
                    (int) collect($parts)
                        ->where('type_beneficiaire', 'proprietaire')
                        ->sum('montant_net') === 16_200
                ))
            );
    }

    // ── Totaux tableau : montant_verse et montant_restant par part ────────────

    public function test_show_parts_livreur_exposent_verse_et_restant(): void
    {
        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $commission->parts()->create(
            $this->partData('livreur', montant_net: 5_400, montant_verse: 5_400, statut: StatutCommission::VERSEE),
        );
        $commission->parts()->create(
            $this->partData('livreur', montant_net: 4_050, montant_verse: 0, statut: StatutCommission::EN_ATTENTE),
        );

        $this->actingAs($this->user)
            ->get(route('commissions.show', $commission))
            ->assertInertia(fn ($page) => $page
                ->where('commission.parts', fn ($parts) => (
                    (int) collect($parts)->where('type_beneficiaire', 'livreur')->sum('montant_verse') === 5_400
                    && (int) collect($parts)->where('type_beneficiaire', 'livreur')->sum('montant_restant') === 4_050
                ))
            );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function partData(
        string $type,
        float $montant_brut = 5_000,
        float $frais = 0,
        float $montant_net = 5_000,
        float $montant_verse = 0,
        StatutCommission $statut = StatutCommission::EN_ATTENTE,
    ): array {
        return [
            'type_beneficiaire' => $type,
            'beneficiaire_nom' => $type === 'livreur' ? 'Test Livreur' : 'Test Proprio',
            'taux_commission' => 40,
            'montant_brut' => $montant_brut,
            'frais_supplementaires' => $frais,
            'montant_net' => $montant_net,
            'montant_verse' => $montant_verse,
            'statut' => $statut,
        ];
    }
}
