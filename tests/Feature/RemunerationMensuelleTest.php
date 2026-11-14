<?php

namespace Tests\Feature;

use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutTypeRemuneration;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Site;
use App\Models\TypeRemuneration;
use App\Services\Rh\RemunerationMensuelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Rémunération mensuelle totale de la fiche salarié (docs/primes-avantages.md, AFF-016) : salaire de
 * base du contrat en cours + primes récurrentes versées en argent, en vigueur au jour ; ponctuelles,
 * montants saisis chaque mois, propositions à valider et avantages en nature jamais additionnés ;
 * aucun total inventé sans contrat ; transmise seulement avec les deux droits de lecture.
 */
class RemunerationMensuelleTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->initOrgAndUser(['rh-employes.read', 'rh-remunerations.read', 'rh-affectations-remuneration.read']);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aides ─────────────────────────────────────────────────────────────────

    private function employe(array $attributs = []): Employe
    {
        return Employe::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'date_embauche' => '2025-01-01',
            ...$attributs,
        ]);
    }

    private function contrat(Employe $employe, array $attributs = []): Contrat
    {
        return Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_contrat' => 'cdi',
            'date_debut' => '2026-09-01',
            'salaire_base' => 1000000,
            'statut_contrat' => 'actif',
            ...$attributs,
        ]);
    }

    private function type(array $attributs = []): TypeRemuneration
    {
        static $n = 0;
        $n++;

        return TypeRemuneration::create([
            'organization_id' => $this->org->id,
            'code' => 'REM_'.$n,
            'libelle' => 'Prime '.$n,
            'categorie' => 'prime',
            'mode_calcul' => 'montant_fixe',
            'periodicite' => 'mensuelle',
            'nature_montant' => 'fixe',
            'proratisable' => true,
            'statut' => StatutTypeRemuneration::ACTIF->value,
            ...$attributs,
        ]);
    }

    private function prime(Employe $employe, TypeRemuneration $type, array $attributs = []): AffectationRemuneration
    {
        return AffectationRemuneration::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_remuneration_id' => $type->id,
            'date_debut' => '2026-11-01',
            'montant' => 100000,
            'statut' => StatutAffectationRemuneration::VALIDEE->value,
            ...$attributs,
        ]);
    }

    private function calcul(Employe $employe): array
    {
        return app(RemunerationMensuelleService::class)->calculer($employe->fresh());
    }

    // ── Calcul ────────────────────────────────────────────────────────────────

    public function test_salarie_sans_prime_le_total_est_le_salaire_de_base(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);

        $r = $this->calcul($employe);

        $this->assertSame(1000000.0, $r['salaire_base']);
        $this->assertSame([], $r['primes']);
        $this->assertSame(0.0, $r['total_primes']);
        $this->assertSame(1000000.0, $r['total']);
    }

    public function test_plusieurs_primes_mensuelles_sont_additionnees(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $this->prime($employe, $this->type(['libelle' => 'Vacance']), ['montant' => 200000]);
        $this->prime($employe, $this->type(['libelle' => 'Transport']), ['montant' => 500000, 'date_fin' => '2027-04-30']);
        $this->prime($employe, $this->type(['libelle' => 'Logement', 'mode_calcul' => 'pourcentage_salaire_base']), ['montant' => null, 'taux' => 10]);

        $r = $this->calcul($employe);

        $this->assertSame(['Logement', 'Transport', 'Vacance'], array_column($r['primes'], 'libelle'));
        $this->assertSame([100000.0, 500000.0, 200000.0], array_column($r['primes'], 'montant'));
        $this->assertSame('10 % du salaire de base', $r['primes'][0]['detail']);
        $this->assertSame(800000.0, $r['total_primes']);
        $this->assertSame(1800000.0, $r['total']);
    }

    public function test_prime_ponctuelle_variable_et_a_valider_ne_sont_pas_comptees(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $this->prime($employe, $this->type(['libelle' => 'Fin d’année', 'periodicite' => 'ponctuelle', 'proratisable' => false]),
            ['date_debut' => '2026-11-01', 'date_fin' => '2026-11-30', 'montant' => 300000]);
        $this->prime($employe, $this->type(['libelle' => 'Rendement', 'nature_montant' => 'variable']), ['montant' => null]);
        $this->prime($employe, $this->type(['libelle' => 'Panier']), ['montant' => 50000, 'statut' => StatutAffectationRemuneration::A_VALIDER->value]);

        $r = $this->calcul($employe);

        $this->assertSame([], $r['primes']);
        $this->assertSame(1000000.0, $r['total']);
        $raisons = collect($r['non_comptees'])->pluck('raison', 'libelle');
        $this->assertStringStartsWith('Ponctuelle', $raisons['Fin d’année']);
        $this->assertStringStartsWith('Montant saisi chaque mois', $raisons['Rendement']);
        $this->assertStringStartsWith('À valider', $raisons['Panier']);
    }

    public function test_prime_terminee_annulee_ou_a_venir_est_ignoree(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $type = $this->type(['cumul_autorise' => true]);
        $this->prime($employe, $type, ['date_debut' => '2026-01-01', 'date_fin' => '2026-10-31']);
        $this->prime($employe, $type, ['statut' => StatutAffectationRemuneration::ANNULEE->value]);
        $this->prime($employe, $type, ['date_debut' => '2026-12-01']);

        $r = $this->calcul($employe);

        $this->assertSame([], $r['primes']);
        $this->assertSame([], $r['non_comptees']);
        $this->assertSame(1000000.0, $r['total']);
    }

    public function test_un_changement_de_montant_valide_n_est_pas_compte_deux_fois(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $type = $this->type();
        $ancienne = $this->prime($employe, $type, ['date_debut' => '2026-09-01', 'date_fin' => '2026-11-09', 'montant' => 100000]);
        $this->prime($employe, $type, ['date_debut' => '2026-11-10', 'montant' => 150000, 'remplace_affectation_id' => $ancienne->id]);

        $r = $this->calcul($employe);

        $this->assertSame([150000.0], array_column($r['primes'], 'montant'));
        $this->assertSame(1150000.0, $r['total']);
    }

    public function test_changement_de_contrat_le_salaire_du_contrat_en_cours_est_retenu(): void
    {
        $employe = $this->employe();
        $this->contrat($employe, ['type_contrat' => 'cdd', 'date_debut' => '2026-01-01', 'date_fin' => '2026-10-31', 'salaire_base' => 800000, 'statut_contrat' => 'termine']);
        $this->contrat($employe, ['date_debut' => '2026-11-01', 'salaire_base' => 1200000]);
        $this->prime($employe, $this->type(['mode_calcul' => 'pourcentage_salaire_base']), ['montant' => null, 'taux' => 10]);

        $r = $this->calcul($employe);

        $this->assertSame('CDI', $r['contrat']['type_contrat_label']);
        $this->assertSame(1200000.0, $r['salaire_base']);
        $this->assertSame(120000.0, $r['total_primes']);
        $this->assertSame(1320000.0, $r['total']);
    }

    public function test_avantage_en_nature_totalise_a_part_jamais_dans_le_total(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $this->prime($employe, $this->type(['libelle' => 'Transport']), ['montant' => 500000]);
        $this->prime($employe, $this->type(['libelle' => 'Logement de fonction', 'categorie' => 'avantage_nature']), ['montant' => 400000]);

        $r = $this->calcul($employe);

        $this->assertSame(['Transport'], array_column($r['primes'], 'libelle'));
        $this->assertSame(['Logement de fonction'], array_column($r['avantages_nature'], 'libelle'));
        $this->assertSame(400000.0, $r['total_avantages_nature']);
        $this->assertSame(1500000.0, $r['total']);
    }

    public function test_sans_contrat_aucun_total_n_est_invente(): void
    {
        $employe = $this->employe();
        $this->contrat($employe, ['date_debut' => '2026-01-01', 'date_fin' => '2026-10-31', 'statut_contrat' => 'termine']);
        $this->prime($employe, $this->type(['libelle' => 'Transport']), ['montant' => 500000]);
        $this->prime($employe, $this->type(['libelle' => 'Logement', 'mode_calcul' => 'pourcentage_salaire_base']), ['montant' => null, 'taux' => 10]);

        $r = $this->calcul($employe);

        $this->assertNull($r['contrat']);
        $this->assertNull($r['salaire_base']);
        $this->assertNull($r['total']);
        $this->assertSame(500000.0, $r['total_primes']);
        $this->assertStringStartsWith('Pourcentage du salaire de base', $r['non_comptees'][0]['raison']);
    }

    public function test_type_desactive_n_est_plus_compte(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $this->prime($employe, $this->type(['statut' => StatutTypeRemuneration::INACTIF->value, 'desactive_au' => '2026-11-01']));
        $this->prime($employe, $this->type(['statut' => StatutTypeRemuneration::INACTIF->value, 'desactive_au' => '2026-12-01']), ['montant' => 70000]);

        $r = $this->calcul($employe);

        $this->assertSame([70000.0], array_column($r['primes'], 'montant'));
        $this->assertCount(1, $r['non_comptees']);
    }

    public function test_salarie_sorti_plus_aucune_remuneration(): void
    {
        $employe = $this->employe(['statut' => 'sorti', 'date_sortie' => '2026-10-31']);
        $this->contrat($employe, ['date_fin' => '2026-10-31', 'statut_contrat' => 'termine']);
        $this->prime($employe, $this->type(), ['date_debut' => '2026-09-01']);

        $r = $this->calcul($employe);

        $this->assertStringStartsWith('Salarié sorti le 31/10/2026', $r['hors_emploi']);
        $this->assertNull($r['total']);
        $this->assertSame([], $r['primes']);
    }

    // ── Fiche salarié ─────────────────────────────────────────────────────────

    public function test_la_fiche_transmet_la_remuneration_et_se_recalcule_a_chaque_ouverture(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $this->prime($employe, $this->type(), ['montant' => 200000]);

        $this->actingAs($this->user)->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page
                ->where('remuneration.total', 1200000)
                ->where('remuneration.salaire_base', 1000000)
                ->where('remuneration.total_primes', 200000));

        $this->prime($employe, $this->type(), ['montant' => 300000]);

        $this->actingAs($this->user)->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('remuneration.total', 1500000));
    }

    public function test_sans_l_un_des_deux_droits_de_lecture_rien_n_est_transmis(): void
    {
        $employe = $this->employe();
        $this->contrat($employe);
        $this->prime($employe, $this->type());

        $this->user->revokePermissionTo('rh-remunerations.read');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->user)->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('remuneration', null));

        Permission::firstOrCreate(['name' => 'rh-remunerations.read', 'guard_name' => 'web']);
        $this->user->givePermissionTo('rh-remunerations.read');
        $this->user->revokePermissionTo('rh-affectations-remuneration.read');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->user->fresh())->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('remuneration', null));
    }
}
