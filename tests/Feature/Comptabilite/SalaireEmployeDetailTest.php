<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutLignePaie;
use App\Enums\StatutPeriodePaie;
use App\Enums\TypeVariablePaie;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\PaieVariable;
use App\Services\PaieCalculService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class SalaireEmployeDetailTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeEmployeAvecContrat(float $salaire = 1_000_000): Employe
    {
        $employe = Employe::create([
            'organization_id' => $this->org->id,
            'matricule' => '000001',
            'nom' => 'CAMARA',
            'prenom' => 'Test',
            'telephone' => '+224622000099',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);

        Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_contrat' => 'cdi',
            'date_debut' => '2025-01-01',
            'salaire_base' => $salaire,
            'statut_contrat' => 'actif',
        ]);

        return $employe;
    }

    private function makeLignePeriode(Employe $employe, int $mois, int $annee): PaieLigne
    {
        $periode = PaiePeriode::create([
            'organization_id' => $this->org->id,
            'mois' => $mois,
            'annee' => $annee,
            'statut' => StatutPeriodePaie::VALIDE_RH,
        ]);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);
        $service->calculerPeriode($periode);

        return PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $employe->id)
            ->firstOrFail();
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_expose_commission_summary_et_details_sur_deux_periodes(): void
    {
        $employe = $this->makeEmployeAvecContrat(1_000_000);
        $this->makeLignePeriode($employe, 1, 2025);
        $this->makeLignePeriode($employe, 2, 2025);

        $this->actingAs($this->user)
            ->get("/comptabilite/salaires/employes/{$employe->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Salaire/Employe/Show')
                ->where('employe.nom', 'Test CAMARA')
                ->where('commission_summary.brut_cumule', 2_000_000)
                ->where('commission_summary.net_a_payer', 2_000_000)
                ->where('commission_summary.deja_paye', fn ($v) => (float) $v === 0.0)
                ->where('commission_summary.reste_a_payer', 2_000_000)
                ->has('commission_details', 2)
                ->where('commission_details.0.vehicule', null)
                ->has('expenses')
                ->has('payments', 0)
            );
    }

    public function test_show_refuse_sans_permission(): void
    {
        $employe = $this->makeEmployeAvecContrat();
        $userSansPermission = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($userSansPermission)
            ->get("/comptabilite/salaires/employes/{$employe->id}")
            ->assertStatus(403);
    }

    // ── paiement agrégé FIFO sur plusieurs périodes ──────────────────────────

    public function test_payer_employe_repartit_le_montant_en_fifo_sur_les_periodes(): void
    {
        $employe = $this->makeEmployeAvecContrat(1_000_000);
        $janvier = $this->makeLignePeriode($employe, 1, 2025);
        $fevrier = $this->makeLignePeriode($employe, 2, 2025);

        // Février a une retenue de 500 000 → net = 500 000 (vs 1 000 000 en janvier),
        // pour vérifier que le FIFO répartit le montant différemment selon le reste réel de chaque période.
        PaieVariable::create([
            'paie_ligne_id' => $fevrier->id,
            'type' => TypeVariablePaie::RETENUE->value,
            'libelle' => 'Test retenue',
            'montant' => 500_000,
        ]);
        app(PaieCalculService::class)->calculerLigne($fevrier);
        $fevrier->refresh();

        $this->actingAs($this->user)
            ->post("/comptabilite/salaires/employes/{$employe->id}/paiements", [
                'montant' => 1_200_000,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $janvier->refresh();
        $fevrier->refresh();

        $this->assertEquals(1_000_000, (float) $janvier->deja_paye);
        $this->assertEquals(0, (float) $janvier->reste_a_payer);
        $this->assertEquals(StatutLignePaie::PAYE, $janvier->statut);

        $this->assertEquals(200_000, (float) $fevrier->deja_paye);
        $this->assertEquals(300_000, (float) $fevrier->reste_a_payer);
        $this->assertEquals(StatutLignePaie::PARTIELLEMENT_PAYE, $fevrier->statut);
    }

    public function test_payer_employe_refuse_montant_superieur_au_total_disponible(): void
    {
        $employe = $this->makeEmployeAvecContrat(500_000);
        $this->makeLignePeriode($employe, 1, 2025);

        $this->actingAs($this->user)
            ->post("/comptabilite/salaires/employes/{$employe->id}/paiements", [
                'montant' => 999_999,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('montant');
    }

    public function test_payer_employe_refuse_sans_permission(): void
    {
        $employe = $this->makeEmployeAvecContrat(500_000);
        $this->makeLignePeriode($employe, 1, 2025);
        $userSansPermission = $this->makeUserWithPermissions($this->org, ['comptabilite.read']);

        $this->actingAs($userSansPermission)
            ->post("/comptabilite/salaires/employes/{$employe->id}/paiements", [
                'montant' => 100_000,
                'mode_paiement' => 'especes',
            ])
            ->assertStatus(403);
    }
}
