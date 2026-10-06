<?php

namespace Tests\Feature\Tresorerie;

use App\Models\CompteTresorerie;
use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use Database\Seeders\CaissesEncaissementDemoSeeder;
use Database\Seeders\ElmDemoAccountsSeeder;
use Database\Seeders\ElmDemoOrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SitesSeeder;
use Database\Seeders\UserSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les comptes de démo qui encaissent (specs E2E, installation locale) reçoivent une caisse dédiée
 * active : sans elle, l'encaissement en espèces leur serait refusé (règle du 23/09/2026).
 */
class CaissesEncaissementDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seederComptes(): void
    {
        $this->seed([
            RolesAndPermissionsSeeder::class,
            ElmDemoOrganizationSeeder::class,
            ElmDemoAccountsSeeder::class,
            SitesSeeder::class,
            UserSitesSeeder::class,
        ]);
    }

    private function compte(string $telephone): User
    {
        return UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($telephone))
            ?? $this->fail("Compte {$telephone} introuvable.");
    }

    public function test_le_compte_e2e_principal_recoit_une_caisse_active_sur_chacun_de_ses_sites(): void
    {
        $this->seederComptes();
        $this->seed(CaissesEncaissementDemoSeeder::class);

        $agent = $this->compte('+33758855039');
        $sites = $agent->sites()->pluck('sites.id')->all();
        $this->assertNotEmpty($sites);

        foreach ($sites as $siteId) {
            $caisse = CompteTresorerie::dediees()->actifs()->where('agent_id', $agent->id)->where('site_id', $siteId)->first();
            $this->assertNotNull($caisse, 'une caisse dédiée active par site');
            $this->assertNotNull($caisse->valide_le);
        }
    }

    public function test_les_autres_membres_du_personnel_n_en_recoivent_pas(): void
    {
        $this->seederComptes();
        $this->seed(CaissesEncaissementDemoSeeder::class);

        // Elhadj Oumar TALL (super_admin à Matoto) n'est pas un compte utilisé par les specs qui encaissent :
        // il reste disponible pour le spec E2E de création d'une caisse dédiée.
        $this->assertSame(0, CompteTresorerie::dediees()->where('agent_id', $this->compte('+33605751596')->id)->count());
    }

    public function test_le_seeder_est_idempotent(): void
    {
        $this->seederComptes();
        $this->seed(CaissesEncaissementDemoSeeder::class);
        $avant = CompteTresorerie::dediees()->count();

        $this->seed(CaissesEncaissementDemoSeeder::class);

        $this->assertGreaterThan(0, $avant);
        $this->assertSame($avant, CompteTresorerie::dediees()->count());
    }

    public function test_sans_les_comptes_il_ne_fait_rien(): void
    {
        $this->seed(CaissesEncaissementDemoSeeder::class);

        $this->assertSame(0, CompteTresorerie::count());
    }
}
