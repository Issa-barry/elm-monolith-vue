<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutPeriodePaie;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\Site;
use App\Models\User;
use App\Services\PaieCalculService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le paiement d'une ligne de paie ne doit être possible que si sa PaiePeriode est
 * VALIDE_RH/PAYE — même règle que PaiePaiementController, désormais partagée via
 * PaiePolicy::pay() (voir SalaireController::payerLigne).
 */
class SalairePayerLigneTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
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

    private function makeLigne(StatutPeriodePaie $statutPeriode): PaieLigne
    {
        $employe = Employe::create([
            'organization_id' => $this->org->id,
            'matricule' => '000001',
            'nom' => 'CAMARA',
            'prenom' => 'Test',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);

        Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_contrat' => 'cdi',
            'date_debut' => '2025-01-01',
            'salaire_base' => 1_000_000,
            'statut_contrat' => 'actif',
        ]);

        $periode = PaiePeriode::create([
            'organization_id' => $this->org->id,
            'mois' => 1,
            'annee' => 2025,
            'statut' => StatutPeriodePaie::VALIDE_RH,
        ]);

        $service = app(PaieCalculService::class);
        $service->genererLignes($periode);
        $service->calculerPeriode($periode);

        // La ligne est générée avec la période VALIDE_RH pour permettre le calcul
        // (verrouillé sinon), puis on bascule au statut voulu pour le test.
        $periode->update(['statut' => $statutPeriode->value]);

        return PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $employe->id)
            ->firstOrFail();
    }

    public function test_payer_ligne_refuse_si_permission_comptabilite_payer_seule(): void
    {
        $ligne = $this->makeLigne(StatutPeriodePaie::VALIDE_RH);
        $user = $this->makeUser(['comptabilite.payer']); // pas de rh-paie.pay

        $this->actingAs($user)
            ->post(route('comptabilite.salaires.payer', $ligne), [
                'montant' => 100_000,
                'date_paiement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('paie_paiements', 0);
    }

    public function test_payer_ligne_refuse_si_periode_brouillon(): void
    {
        $ligne = $this->makeLigne(StatutPeriodePaie::BROUILLON);
        $user = $this->makeUser(['comptabilite.payer', 'rh-paie.pay']);

        $this->actingAs($user)
            ->post(route('comptabilite.salaires.payer', $ligne), [
                'montant' => 100_000,
                'date_paiement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('paie_paiements', 0);
    }

    public function test_payer_ligne_refuse_si_periode_calcule(): void
    {
        $ligne = $this->makeLigne(StatutPeriodePaie::CALCULE);
        $user = $this->makeUser(['comptabilite.payer', 'rh-paie.pay']);

        $this->actingAs($user)
            ->post(route('comptabilite.salaires.payer', $ligne), [
                'montant' => 100_000,
                'date_paiement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('paie_paiements', 0);
    }

    public function test_payer_ligne_autorise_si_periode_valide_rh(): void
    {
        $ligne = $this->makeLigne(StatutPeriodePaie::VALIDE_RH);
        $user = $this->makeUser(['comptabilite.payer', 'rh-paie.pay']);

        $this->actingAs($user)
            ->post(route('comptabilite.salaires.payer', $ligne), [
                'montant' => 100_000,
                'date_paiement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(100_000, (float) $ligne->fresh()->deja_paye);
    }
}
