<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Régression : le login (Fortify::authenticateUsing, cf. FortifyServiceProvider) normalise
 * toujours le téléphone soumis en E.164 avant de comparer à `users.telephone` — un compte créé
 * avec un téléphone non normalisé (ex: "758855039" au lieu de "+33758855039") est inutilisable,
 * quel que soit le mot de passe. Bug réel constaté en production le 10/08/2026.
 */
class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::factory()->create(['slug' => 'elm']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    private function envVars(array $overrides = []): array
    {
        return array_merge([
            'SUPER_ADMIN_PRENOM' => 'Test',
            'SUPER_ADMIN_NOM' => 'Admin',
            'SUPER_ADMIN_TELEPHONE' => '758855039',
            'SUPER_ADMIN_CODE_PAYS' => 'FR',
            'SUPER_ADMIN_PASSWORD' => 'Password1234!',
        ], $overrides);
    }

    private function withEnv(array $vars, callable $callback): void
    {
        foreach ($vars as $key => $value) {
            putenv("{$key}={$value}");
        }
        try {
            $callback();
        } finally {
            foreach (array_keys($vars) as $key) {
                putenv($key);
            }
        }
    }

    public function test_telephone_saisi_sans_indicatif_est_normalise_en_e164(): void
    {
        $this->withEnv($this->envVars(), function () {
            $this->seed(SuperAdminSeeder::class);
        });

        $this->assertDatabaseHas('users', ['telephone' => '+33758855039']);
        $this->assertDatabaseMissing('users', ['telephone' => '758855039']);
    }

    public function test_telephone_saisi_avec_zero_initial_est_normalise(): void
    {
        $this->withEnv($this->envVars(['SUPER_ADMIN_TELEPHONE' => '0758855039']), function () {
            $this->seed(SuperAdminSeeder::class);
        });

        $this->assertDatabaseHas('users', ['telephone' => '+33758855039']);
    }

    public function test_telephone_deja_au_format_e164_nest_pas_double_prefixe(): void
    {
        $this->withEnv($this->envVars(['SUPER_ADMIN_TELEPHONE' => '+33758855039']), function () {
            $this->seed(SuperAdminSeeder::class);
        });

        $this->assertDatabaseHas('users', ['telephone' => '+33758855039']);
    }

    public function test_telephone_normalise_correspond_a_ce_que_construit_le_formulaire_de_connexion(): void
    {
        // Même logique que resources/js/pages/auth/Login.vue : fullPhone = prefix + digits
        // sans le 0 initial (ex: "+33" + "758855039").
        $this->withEnv($this->envVars(), function () {
            $this->seed(SuperAdminSeeder::class);
        });

        $submittedByLoginForm = '+33'.ltrim('758855039', '0');
        $this->assertSame('+33758855039', $submittedByLoginForm);
        $this->assertDatabaseHas('users', ['telephone' => $submittedByLoginForm]);
    }

    public function test_re_seed_avec_meme_telephone_met_a_jour_le_meme_compte(): void
    {
        $this->withEnv($this->envVars(), function () {
            $this->seed(SuperAdminSeeder::class);
        });
        $this->withEnv($this->envVars(['SUPER_ADMIN_PASSWORD' => 'AutrePassword5678!']), function () {
            $this->seed(SuperAdminSeeder::class);
        });

        $this->assertSame(1, User::where('telephone', '+33758855039')->count());
    }
}
