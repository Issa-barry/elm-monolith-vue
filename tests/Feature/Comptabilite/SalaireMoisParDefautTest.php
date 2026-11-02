<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutPeriodePaie;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\HasPaieSalaries;
use Tests\TestCase;

/**
 * Comptabilité > Paiement salaire, mois affiché par défaut (docs/paie.md) : le mois précédent tant
 * qu'il reste un salarié à payer, sinon le mois en cours. Un mois choisi reste respecté.
 */
class SalaireMoisParDefautTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasPaieSalaries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer', 'rh-paie.validate']);
        Carbon::setTestNow('2026-11-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assertMoisAffiche(int $mois, int $annee, array $query = [], ?User $user = null): void
    {
        $this->actingAs($user ?? $this->user)->get(route('comptabilite.salaires.index', $query))
            ->assertInertia(fn (Assert $page) => $page->component('Comptabilite/Salaire/Index')
                ->where('filtre_mois', $mois)
                ->where('filtre_annee', $annee)
                ->where('periode.mois', $mois)
                ->where('periode.annee', $annee));
    }

    private function solder(PaiePeriode $periode, float $reste = 0): void
    {
        PaieLigne::where('paie_periode_id', $periode->id)->update(['reste_a_payer' => $reste]);
    }

    public function test_le_mois_precedent_s_affiche_tant_qu_un_salarie_reste_a_payer(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $octobre = $this->creerPeriodeCalculee(10, 2026, StatutPeriodePaie::CALCULE);

        $this->assertMoisAffiche(10, 2026);
        // Consulter ne crée pas la période du mois en cours.
        $this->assertFalse(PaiePeriode::where('organization_id', $this->org->id)->where('mois', 11)->exists());

        $this->solder($octobre, 500_000);
        $this->assertMoisAffiche(10, 2026);
    }

    public function test_le_mois_en_cours_s_affiche_quand_tout_est_paye_ou_sans_paie_precedente(): void
    {
        $this->assertMoisAffiche(11, 2026);

        $this->creerSalarie($this->user->sites()->first());
        $this->solder($this->creerPeriodeCalculee(10, 2026, StatutPeriodePaie::VALIDE_RH));

        $this->assertMoisAffiche(11, 2026);
    }

    public function test_un_mois_choisi_reste_respecte(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $this->creerPeriodeCalculee(10, 2026, StatutPeriodePaie::CALCULE);

        $this->assertMoisAffiche(11, 2026, ['mois' => 11, 'annee' => 2026]);
        $this->assertMoisAffiche(9, 2026, ['mois' => 9]);
    }

    public function test_janvier_regarde_decembre_de_l_annee_precedente(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $this->creerPeriodeCalculee(12, 2026, StatutPeriodePaie::CALCULE);
        Carbon::setTestNow('2027-01-15 10:00:00');

        $this->assertMoisAffiche(12, 2026);
    }

    public function test_le_dernier_jour_d_un_mois_long_regarde_bien_le_mois_precedent(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $this->creerPeriodeCalculee(11, 2026, StatutPeriodePaie::CALCULE);
        Carbon::setTestNow('2026-12-31 10:00:00');

        $this->assertMoisAffiche(11, 2026);
    }

    public function test_une_periode_cloturee_n_est_plus_proposee(): void
    {
        $this->creerSalarie($this->user->sites()->first());
        $this->creerPeriodeCalculee(10, 2026, StatutPeriodePaie::CLOTURE);

        $this->assertMoisAffiche(11, 2026);
    }

    public function test_seuls_les_salaries_du_perimetre_de_l_utilisateur_comptent(): void
    {
        $kaloum = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->creerSalarie($kaloum);
        $this->creerPeriodeCalculee(10, 2026, StatutPeriodePaie::CALCULE);

        $this->assertMoisAffiche(10, 2026, user: $this->creerComptable([$kaloum]));
        $this->assertMoisAffiche(11, 2026, user: $this->creerComptable([$matoto]));
    }

    public function test_les_salaries_d_une_autre_organisation_ne_comptent_pas(): void
    {
        $autre = Organization::factory()->create();
        $this->creerSalarie(Site::create(['organization_id' => $autre->id, 'nom' => 'Ailleurs', 'type' => 'depot']), org: $autre);
        $this->creerPeriodeCalculee(10, 2026, StatutPeriodePaie::CALCULE, $autre);

        $this->assertMoisAffiche(11, 2026);
    }
}
