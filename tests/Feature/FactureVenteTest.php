<?php

namespace Tests\Feature;

use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class FactureVenteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read']);
    }

    private function makeFacture(array $overrides = []): FactureVente
    {
        $commande = CommandeVente::factory()->create(['organization_id' => $this->org->id]);

        return FactureVente::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 10000,
            'statut_facture' => 'impayee',
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('factures.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('factures.index'))
            ->assertStatus(403);
    }

    public function test_index_accepts_periode_parameter(): void
    {
        foreach (['today', 'week', 'month', 'all'] as $periode) {
            $this->actingAs($this->user)
                ->get(route('factures.index', ['periode' => $periode]))
                ->assertStatus(200);
        }
    }

    public function test_index_returns_statut_prop(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('statut')
                ->where('statut', 'tous')
            );
    }

    public function test_index_only_shows_factures_for_own_organization(): void
    {
        $this->makeFacture(['montant_net' => 10000]);

        $otherOrg = Organization::factory()->create();
        $otherCommande = CommandeVente::factory()->create(['organization_id' => $otherOrg->id]);
        FactureVente::factory()->create([
            'organization_id' => $otherOrg->id,
            'commande_vente_id' => $otherCommande->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('factures', 1)
            );
    }

    public function test_index_shows_correct_totaux(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('totaux')
                ->has('modes_paiement')
                ->has('periode')
                ->has('statut')
            );
    }

    // ── stats/filtres ─────────────────────────────────────────────────────────

    public function test_totaux_include_all_statuts_without_filter(): void
    {
        $this->makeFacture(['montant_net' => 10000, 'statut_facture' => 'impayee']);
        $this->makeFacture(['montant_net' => 5000, 'statut_facture' => 'payee']);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertInertia(fn ($page) => $page
                ->where('statut', 'tous')
                ->where('totaux.nb_impayees', 1)
                ->where('totaux.nb_payees', 1)
                ->where('totaux.montant_impayees', 10000)
                ->where('totaux.montant_payees', 5000)
            );
    }

    public function test_statut_filter_restricts_factures_and_totaux(): void
    {
        $this->makeFacture(['montant_net' => 10000, 'statut_facture' => 'impayee']);
        $this->makeFacture(['montant_net' => 5000, 'statut_facture' => 'payee']);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'statut' => 'impayee']))
            ->assertInertia(fn ($page) => $page
                ->where('statut', 'impayee')
                ->has('factures', 1)
                ->where('totaux.nb_impayees', 1)
                ->where('totaux.nb_payees', 0)
                ->where('totaux.montant_impayees', 10000)
                ->where('totaux.montant_payees', 0)
            );
    }

    public function test_statut_filter_payee_excludes_impayees(): void
    {
        $this->makeFacture(['montant_net' => 8000, 'statut_facture' => 'impayee']);
        $this->makeFacture(['montant_net' => 3000, 'statut_facture' => 'payee']);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'statut' => 'payee']))
            ->assertInertia(fn ($page) => $page
                ->where('statut', 'payee')
                ->has('factures', 1)
                ->where('totaux.nb_payees', 1)
                ->where('totaux.nb_impayees', 0)
                ->where('totaux.total_a_encaisser', 0)
            );
    }

    public function test_totaux_total_a_encaisser_excludes_payees_and_annulees(): void
    {
        $this->makeFacture(['montant_net' => 10000, 'statut_facture' => 'impayee']);
        $this->makeFacture(['montant_net' => 4000, 'statut_facture' => 'partiel']);
        $this->makeFacture(['montant_net' => 5000, 'statut_facture' => 'payee']);
        $this->makeFacture(['montant_net' => 2000, 'statut_facture' => 'annulee']);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertInertia(fn ($page) => $page
                ->where('totaux.nb_impayees', 1)
                ->where('totaux.nb_partielles', 1)
                ->where('totaux.nb_payees', 1)
            );
    }

    public function test_statut_filter_partiel(): void
    {
        $this->makeFacture(['montant_net' => 10000, 'statut_facture' => 'impayee']);
        $this->makeFacture(['montant_net' => 6000, 'statut_facture' => 'partiel']);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'statut' => 'partiel']))
            ->assertInertia(fn ($page) => $page
                ->where('statut', 'partiel')
                ->has('factures', 1)
                ->where('totaux.nb_partielles', 1)
                ->where('totaux.nb_impayees', 0)
            );
    }

    public function test_statut_tous_is_default(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all']))
            ->assertInertia(fn ($page) => $page->where('statut', 'tous'));
    }
}
