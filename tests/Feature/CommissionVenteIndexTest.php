<?php

namespace Tests\Feature;

use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionVenteIndexTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('commissions.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('commissions.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('commissions.index'))
            ->assertStatus(403);
    }

    public function test_index_accepts_all_periode_values(): void
    {
        foreach (['today', 'week', 'month', 'all'] as $periode) {
            $this->actingAs($this->user)
                ->get(route('commissions.index', ['periode' => $periode]))
                ->assertStatus(200);
        }
    }

    public function test_index_returns_expected_inertia_data(): void
    {
        $this->actingAs($this->user)
            ->get(route('commissions.index'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('beneficiaires')
                ->has('totaux')
                ->has('periode')
                ->has('tab')
            );
    }

    public function test_index_livreurs_affiche_uniquement_la_part_principale(): void
    {
        $livreur1 = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $livreur2 = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $commission = CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $commission->parts()->createMany([
            array_merge($this->livreurPartData('principal', 'Oumar CAMARA', 27_000), ['livreur_id' => $livreur1->id]),
            array_merge($this->livreurPartData('assistant', 'Abdoulaye SYLLA', 20_250), ['livreur_id' => $livreur2->id]),
        ]);

        $this->actingAs($this->user)
            ->get(route('commissions.index', ['tab' => 'livreurs', 'periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('beneficiaires', 1)
                ->where('beneficiaires.0.type_beneficiaire', 'livreur')
                ->where('beneficiaires.0.beneficiaire_nom', 'Oumar CAMARA')
            );
    }

    public function test_index_only_shows_commissions_for_own_organization(): void
    {
        CommissionVente::factory()->create(['organization_id' => $this->org->id]);

        $otherOrg = Organization::factory()->create();
        CommissionVente::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->get(route('commissions.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('beneficiaires')
            );
    }

    public function test_index_totaux_keys_are_present(): void
    {
        $this->actingAs($this->user)
            ->get(route('commissions.index', ['periode' => 'all']))
            ->assertInertia(fn ($page) => $page
                ->where('totaux.nb_en_attente', 0)
                ->where('totaux.nb_versee', 0)
            );
    }

    private function livreurPartData(string $role, string $nom, float $montant): array
    {
        return [
            'type_beneficiaire' => 'livreur',
            'beneficiaire_nom' => $nom,
            'role' => $role,
            'taux_commission' => 20,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => 'en_attente',
        ];
    }
}
