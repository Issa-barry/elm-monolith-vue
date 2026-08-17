<?php

namespace Tests\Feature;

use App\Enums\DomaineActivite;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Services\InstallationService;
use App\Services\OtpService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Garantit la séparation stricte "CI/CD = infrastructure, /install = donnée métier" sur une
 * instance on-premise neuve : le pipeline de déploiement (deploy-hostinger-admin.yml /
 * deploy-hostinger-formation.yml) lance `migrate --force` puis
 * `db:seed --class=RolesAndPermissionsSeeder --force` à chaque déploiement, y compris le premier
 * sur une base vide. Ce seeder ne doit produire QUE des données globales (permissions, rôles
 * système `organization_id IS NULL`) — jamais d'organisation, jamais avant que l'administrateur
 * n'ait lui-même complété l'assistant `/install`.
 *
 * Historique : avant correction, RolesAndPermissionsSeeder::run() créait aussi en dur
 * l'organisation "elm" (Eau la maman), visible en base dès le premier déploiement CI/CD, sans
 * jamais passer par `/install`.
 */
class ProductionDeploySeedingTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'issa@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['app.deployment_mode' => 'on_premise']);

        // Email obligatoire en on_premise (cf. InstallationService::install()) — pré-vérifié ici
        // pour que payload() reste utilisable telle quelle par tous les tests de ce fichier, qui
        // ne portent pas sur la règle email elle-même (cf. InstallWizardTest pour ça).
        app(OtpService::class)->generate(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);
        app(OtpService::class)->markVerified(self::EMAIL, InstallationService::EMAIL_OTP_CONTEXT);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'organisation' => ['nom' => 'Eau la maman', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            'admin' => [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+224622000000',
                'email' => self::EMAIL,
                'password' => 'Sup3r$ecretPwd',
                'password_confirmation' => 'Sup3r$ecretPwd',
            ],
        ], $overrides);
    }

    public function test_base_fraichement_migree_ne_contient_aucune_organisation(): void
    {
        $this->assertSame(0, Organization::count());
        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_seed_de_deploiement_ne_cree_aucune_organisation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, Organization::count());
        // Le seeder doit malgré tout avoir rempli son rôle réel : rôles système + permissions.
        $this->assertGreaterThan(0, Permission::count());
        $this->assertTrue(Role::whereNull('organization_id')->where('name', 'super_admin')->exists());
    }

    public function test_install_reste_accessible_apres_le_seed_de_deploiement(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->get('/install')->assertOk();
        $this->assertSame(0, Organization::count());
    }

    public function test_install_cree_lorganisation_apres_le_seed_de_deploiement(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->post('/install', $this->payload())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Install/Success'));

        $this->assertSame(1, Organization::count());
        $this->assertTrue(AppInstallation::isInstalled());

        $org = Organization::sole();
        $this->assertSame('Eau la maman', $org->name);
    }

    public function test_install_est_verrouille_apres_installation_complete(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->post('/install', $this->payload())->assertOk();

        $this->get('/install')->assertRedirect(route('login'));
        $this->post('/install', $this->payload())->assertStatus(404);
    }

    /**
     * Simule un second déploiement (redeploy) sur une instance déjà installée : le pipeline
     * relance `db:seed --class=RolesAndPermissionsSeeder --force` à chaque déploiement, y compris
     * ceux qui suivent l'installation initiale — cela ne doit ni dupliquer l'organisation, ni
     * altérer la ligne existante.
     */
    public function test_redeploiement_apres_installation_ne_duplique_ni_naltere_lorganisation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->post('/install', $this->payload())->assertOk();

        $orgAvant = Organization::sole()->toArray();

        // Redéploiement suivant : mêmes commandes CI/CD rejouées sur une base déjà installée.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(1, Organization::count());
        $this->assertSame($orgAvant, Organization::sole()->toArray());
    }
}
