<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class PaiementFicheTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
    }

    private function defaultSite(): Site
    {
        return $this->user->sites()->wherePivot('is_default', true)->first();
    }

    private function makePeriode(): PaiementPeriode
    {
        return PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-202606-0001',
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => StatutPeriodePaiement::VALIDEE->value,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeFiche(array $override = []): PaiementFiche
    {
        $periode = $this->makePeriode();
        $site = $this->defaultSite();

        $fiche = PaiementFiche::create(array_merge([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-202606-0001',
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => 'fake-livreur-id',
            'beneficiaire_nom' => 'Diallo Mamadou',
            'site_id' => $site->id,
            'montant_brut' => 300000,
            'total_deductions' => 0,
            'montant_net' => 300000,
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
        ], $override));

        PaiementFicheLigne::create([
            'fiche_id' => $fiche->id,
            'type_ligne' => 'commission_vente',
            'libelle' => 'Commission livraison',
            'montant' => 300000,
            'ordre' => 1,
        ]);

        return $fiche;
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_retourne_200(): void
    {
        $fiche = $this->makeFiche();

        $this->actingAs($this->user)
            ->get(route('comptabilite.fiches.show', $fiche))
            ->assertStatus(200);
    }

    public function test_show_redirige_non_authentifie(): void
    {
        $fiche = $this->makeFiche();

        $this->get(route('comptabilite.fiches.show', $fiche))
            ->assertRedirect(route('login'));
    }

    // ── paiement store ────────────────────────────────────────────────────────

    public function test_paiement_enregistre_sur_fiche(): void
    {
        $fiche = $this->makeFiche();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 100000,
                'mode_paiement' => 'especes',
                'date_paiement' => '2026-06-15',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_fiche_paiements', [
            'fiche_id' => $fiche->id,
            'montant' => 100000,
            'mode_paiement' => 'especes',
        ]);
    }

    public function test_paiement_partiel_change_statut_en_partiellement_paye(): void
    {
        $fiche = $this->makeFiche();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 100000,
                'mode_paiement' => 'especes',
                'date_paiement' => '2026-06-15',
            ]);

        $this->assertDatabaseHas('paiement_fiches', [
            'id' => $fiche->id,
            'statut' => StatutFichePaiement::PARTIELLEMENT_PAYE->value,
        ]);
    }

    public function test_paiement_complet_change_statut_en_paye(): void
    {
        $fiche = $this->makeFiche();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 300000,
                'mode_paiement' => 'virement',
                'date_paiement' => '2026-06-15',
            ]);

        $this->assertDatabaseHas('paiement_fiches', [
            'id' => $fiche->id,
            'statut' => StatutFichePaiement::PAYE->value,
        ]);
    }

    public function test_montant_paiement_ne_peut_depasser_net(): void
    {
        $fiche = $this->makeFiche();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 999999,
                'mode_paiement' => 'especes',
                'date_paiement' => '2026-06-15',
            ])
            ->assertSessionHasErrors('montant');
    }

    public function test_paiement_sans_montant_echoue(): void
    {
        $fiche = $this->makeFiche();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'mode_paiement' => 'especes',
                'date_paiement' => '2026-06-15',
            ])
            ->assertSessionHasErrors('montant');
    }

    // ── access control ────────────────────────────────────────────────────────

    public function test_fiche_autre_organisation_non_accessible(): void
    {
        $autreOrg = \App\Models\Organization::factory()->create();
        $autreSite = Site::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Agence Externe',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $autrePeriode = PaiementPeriode::create([
            'organization_id' => $autreOrg->id,
            'reference' => 'PAY-202606-9999',
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => StatutPeriodePaiement::VALIDEE->value,
            'created_by' => $this->user->id,
        ]);

        $autreFiche = PaiementFiche::create([
            'organization_id' => $autreOrg->id,
            'periode_id' => $autrePeriode->id,
            'reference' => 'FIC-202606-9999',
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => 'fake-id',
            'beneficiaire_nom' => 'Outsider',
            'site_id' => $autreSite->id,
            'montant_brut' => 100000,
            'total_deductions' => 0,
            'montant_net' => 100000,
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('comptabilite.fiches.show', $autreFiche))
            ->assertStatus(403);
    }

    // ── paiement destroy ──────────────────────────────────────────────────────

    public function test_suppression_paiement_recalcule_statut(): void
    {
        $fiche = $this->makeFiche();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 300000,
                'mode_paiement' => 'especes',
                'date_paiement' => '2026-06-15',
            ]);

        $fiche->refresh();
        $this->assertSame(StatutFichePaiement::PAYE->value, $fiche->statut->value);

        $paiement = PaiementFichePaiement::where('fiche_id', $fiche->id)->first();

        $this->actingAs($this->user)
            ->delete(route('comptabilite.fiches.paiements.destroy', $paiement))
            ->assertRedirect();

        $fiche->refresh();
        $this->assertSame(StatutFichePaiement::A_PAYER->value, $fiche->statut->value);
    }
}
