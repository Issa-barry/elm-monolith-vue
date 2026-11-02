<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaie;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PaiePeriode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\HasPaieSalaries;
use Tests\TestCase;

/**
 * « Valider la période » de Comptabilité > Paiement salaire : sans cette action, aucune période ne
 * pouvait atteindre le statut validé et aucun salaire n'était payable (audit RH du 26/10/2026).
 */
class SalaireValidationPeriodeTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, HasPaieSalaries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer', 'rh-paie.validate']);
    }

    private function valider(PaiePeriode $periode)
    {
        return $this->post(route('comptabilite.salaires.periodes.valider', $periode));
    }

    public function test_valider_une_periode_brouillon_calculee_la_rend_payable(): void
    {
        $employe = $this->creerSalarie($this->user->sites()->first());
        $this->equiperPayeurEspeces($this->user, $employe->site_id);
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::BROUILLON);

        $this->actingAs($this->user)->valider($periode)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(StatutPeriodePaie::VALIDE_RH, $periode->fresh()->statut);
        $this->assertTrue(AuditLog::where('auditable_id', $periode->id)
            ->where('event_code', AuditEvent::VALIDATED->value)
            ->exists());

        $this->post(route('comptabilite.salaires.payer', $this->ligneDe($periode, $employe)), [
            'montant' => 1_000_000,
            'date_paiement' => '2025-01-31',
            'mode_paiement' => 'especes',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1_000_000, (float) $this->ligneDe($periode, $employe)->deja_paye);
    }

    public function test_valider_une_periode_calculee(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $periode = $this->creerPeriodeCalculee(2, 2025, StatutPeriodePaie::CALCULE);

        $this->actingAs($this->user)->valider($periode)->assertSessionHasNoErrors();

        $this->assertSame(StatutPeriodePaie::VALIDE_RH, $periode->fresh()->statut);
    }

    public function test_refuse_sans_permission_de_validation(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::BROUILLON);
        $sansDroit = $this->makeUserWithPermissions($this->org, ['comptabilite.read', 'comptabilite.payer']);

        $this->actingAs($sansDroit)->valider($periode)->assertForbidden();

        $this->assertSame(StatutPeriodePaie::BROUILLON, $periode->fresh()->statut);
    }

    public function test_refuse_une_periode_deja_validee(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);

        $this->actingAs($this->user)->valider($periode)->assertSessionHasErrors('periode');
    }

    public function test_refuse_une_periode_sans_ligne(): void
    {
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::BROUILLON);

        $this->actingAs($this->user)->valider($periode)->assertSessionHasErrors('periode');

        $this->assertSame(StatutPeriodePaie::BROUILLON, $periode->fresh()->statut);
    }

    public function test_refuse_la_periode_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->creerSalarie(null, org: $autreOrg);
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::BROUILLON, $autreOrg);

        $this->actingAs($this->user)->valider($periode)->assertForbidden();

        $this->assertSame(StatutPeriodePaie::BROUILLON, $periode->fresh()->statut);
    }

    public function test_un_super_admin_ne_valide_pas_la_periode_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->creerSalarie(null, org: $autreOrg);
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::BROUILLON, $autreOrg);

        $this->actingAs($this->creerSuperAdmin($this->org))->valider($periode)->assertForbidden();

        $this->assertSame(StatutPeriodePaie::BROUILLON, $periode->fresh()->statut);
    }

    public function test_l_ecran_propose_la_validation_seulement_si_elle_est_possible(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::BROUILLON);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_valider_periode', true)
                ->where('periode.payable', false)
                ->where('lignes.0.peut_payer', false));

        $sansDroit = $this->makeUserWithPermissions($this->org, ['comptabilite.read', 'comptabilite.payer']);
        $sansDroit->sites()->attach($this->user->sites()->first()->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansDroit)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page->where('can_valider_periode', false));
    }
}
