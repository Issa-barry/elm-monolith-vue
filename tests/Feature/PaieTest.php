<?php

namespace Tests\Feature;

use App\Enums\StatutLignePaie;
use App\Enums\StatutPeriodePaie;
use App\Enums\TypeVariablePaie;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\PaieVariable;
use App\Models\Site;
use App\Models\User;
use App\Services\PaieCalculService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaieTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
        $this->user = $this->makeUser([
            'rh-paie.read', 'rh-paie.create', 'rh-paie.update', 'rh-paie.delete',
            'rh-paie.validate', 'rh-paie.pay', 'rh-paie.close',
        ]);
    }

    private function makeUser(array $permissions): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeEmployeAvecContrat(string $dateDebut, ?string $dateFin = null, float $salaire = 1_000_000): array
    {
        static $seq = 0;
        $seq++;

        $employe = Employe::create([
            'organization_id' => $this->org->id,
            'matricule' => str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'nom' => 'BARRY',
            'prenom' => 'Test',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);

        $contrat = Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_contrat' => 'cdi',
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'salaire_base' => $salaire,
            'statut_contrat' => 'actif',
        ]);

        return [$employe, $contrat];
    }

    private function makePeriode(array $overrides = []): PaiePeriode
    {
        return PaiePeriode::create(array_merge([
            'organization_id' => $this->org->id,
            'mois' => 1,
            'annee' => 2025,
            'statut' => StatutPeriodePaie::BROUILLON,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_retourne_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('paie.index'))
            ->assertStatus(200);
    }

    public function test_index_redirige_non_authentifie(): void
    {
        $this->get(route('paie.index'))->assertRedirect(route('login'));
    }

    public function test_index_retourne_403_sans_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        $this->actingAs($user)->get(route('paie.index'))->assertStatus(403);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_retourne_200(): void
    {
        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->get(route('paie.show', $periode))
            ->assertStatus(200);
    }

    public function test_show_retourne_403_autre_org(): void
    {
        $autreOrg = Organization::factory()->create();
        $periode = PaiePeriode::create([
            'organization_id' => $autreOrg->id,
            'mois' => 1,
            'annee' => 2025,
            'statut' => StatutPeriodePaie::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->get(route('paie.show', $periode))
            ->assertStatus(403);
    }

    // ── calcul salaire complet ────────────────────────────────────────────────

    public function test_calcul_brut_net_sans_variable(): void
    {
        $periode = $this->makePeriode(['mois' => 1, 'annee' => 2025]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-01', null, 1_000_000);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);
        $service->calculerPeriode($periode);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $employe->id)
            ->firstOrFail();

        $this->assertEquals(1_000_000, (float) $ligne->salaire_base);
        $this->assertEquals(1_000_000, (float) $ligne->brut);
        $this->assertEquals(0, (float) $ligne->deductions);
        $this->assertEquals(1_000_000, (float) $ligne->net);
        $this->assertEquals(StatutLignePaie::CALCULE, $ligne->statut);
    }

    public function test_calcul_avec_prime_et_retenue(): void
    {
        $periode = $this->makePeriode(['mois' => 2, 'annee' => 2025]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-01', null, 500_000);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $employe->id)
            ->firstOrFail();

        PaieVariable::create([
            'paie_ligne_id' => $ligne->id,
            'type' => TypeVariablePaie::PRIME->value,
            'libelle' => 'Prime transport',
            'montant' => 50_000,
        ]);

        PaieVariable::create([
            'paie_ligne_id' => $ligne->id,
            'type' => TypeVariablePaie::RETENUE->value,
            'libelle' => 'Mutuelle',
            'montant' => 20_000,
        ]);

        $service->calculerLigne($ligne);
        $ligne->refresh();

        $this->assertEquals(550_000, (float) $ligne->brut);
        $this->assertEquals(20_000, (float) $ligne->deductions);
        $this->assertEquals(530_000, (float) $ligne->net);
    }

    // ── prorata ───────────────────────────────────────────────────────────────

    public function test_prorata_contrat_mi_mois(): void
    {
        // Janvier 2025 = 31 jours, contrat commence le 16 → 16 jours actifs
        $periode = $this->makePeriode(['mois' => 1, 'annee' => 2025]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-16', null, 310_000);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $employe->id)
            ->firstOrFail();

        // jours actifs = 31 - 16 + 1 = 16
        $this->assertEquals(16, (float) $ligne->jours_travailles);
        $this->assertEquals(31, $ligne->jours_periode);

        $expected = round(310_000 * 16 / 31, 2);
        $this->assertEquals($expected, (float) $ligne->salaire_base);
    }

    public function test_prorata_contrat_plein_mois_pas_de_prorata(): void
    {
        $periode = $this->makePeriode(['mois' => 1, 'annee' => 2025]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-01', null, 1_000_000);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $employe->id)
            ->firstOrFail();

        $this->assertEquals(31, (float) $ligne->jours_travailles);
        $this->assertEquals(1_000_000, (float) $ligne->salaire_base);
    }

    // ── paiements partiels ────────────────────────────────────────────────────

    public function test_paiement_partiel_met_a_jour_reste(): void
    {
        $periode = $this->makePeriode(['statut' => StatutPeriodePaie::VALIDE_RH]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-01', null, 1_000_000);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);
        $service->calculerPeriode($periode);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('paie-paiements.store', $ligne), [
                'montant' => 400_000,
                'date_paiement' => '2025-01-31',
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $ligne->refresh();
        $this->assertEquals(400_000, (float) $ligne->deja_paye);
        $this->assertEquals(600_000, (float) $ligne->reste_a_payer);
        $this->assertEquals(StatutLignePaie::PARTIELLEMENT_PAYE, $ligne->statut);
    }

    public function test_paiement_complet_marque_paye(): void
    {
        $periode = $this->makePeriode(['statut' => StatutPeriodePaie::VALIDE_RH]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-01', null, 1_000_000);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);
        $service->calculerPeriode($periode);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('paie-paiements.store', $ligne), [
                'montant' => 1_000_000,
                'date_paiement' => '2025-01-31',
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $ligne->refresh();
        $this->assertEquals(StatutLignePaie::PAYE, $ligne->statut);
        $this->assertEquals(0, (float) $ligne->reste_a_payer);
    }

    public function test_paiement_depasse_reste_est_rejete(): void
    {
        $periode = $this->makePeriode(['statut' => StatutPeriodePaie::VALIDE_RH]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-01', null, 500_000);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);
        $service->calculerPeriode($periode);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('paie-paiements.store', $ligne), [
                'montant' => 999_999,
                'date_paiement' => '2025-01-31',
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('montant');
    }

    // ── modification bloquée sur période verrouillée ──────────────────────────

    public function test_variable_bloquee_si_periode_verrouillee(): void
    {
        $periode = $this->makePeriode(['statut' => StatutPeriodePaie::VALIDE_RH]);
        [$employe, $contrat] = $this->makeEmployeAvecContrat('2025-01-01', null, 1_000_000);

        $service = app(PaieCalculService::class);
        $periode->update(['statut' => StatutPeriodePaie::CALCULE]);
        $service->genererLignes($periode);
        $service->calculerPeriode($periode);
        $periode->update(['statut' => StatutPeriodePaie::VALIDE_RH]);

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('paie-variables.store', $ligne), [
                'type' => 'prime',
                'libelle' => 'Bonus',
                'montant' => 10_000,
            ])
            ->assertSessionHasErrors('statut');
    }
}
