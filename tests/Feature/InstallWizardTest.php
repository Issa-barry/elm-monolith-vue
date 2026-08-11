<?php

namespace Tests\Feature;

use App\Models\AppInstallation;
use App\Models\Categorie;
use App\Models\OptionCatalogue;
use App\Models\Organization;
use App\Models\TypeVehicule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * /install — parcours web de InstallationService (même service que `php artisan app:install`,
 * cf. InstallAppTest). Le Super Admin choisit directement son mot de passe dans le formulaire.
 */
class InstallWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // throttle:install (10/min/IP, cf. FortifyServiceProvider) est testé fonctionnellement
        // ailleurs (comportement métier) — le désactiver ici évite que le grand nombre de
        // requêtes de cette suite sur les mêmes routes /install* ne se percute lui-même.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'organisation' => ['nom' => 'ELM Test'],
            'admin' => [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+224622000000',
                'email' => null,
                'password' => 'Sup3r$ecretPwd',
                'password_confirmation' => 'Sup3r$ecretPwd',
            ],
            'catalogue' => ['categories' => false, 'options' => false, 'types_vehicule' => false],
        ], $overrides);
    }

    // ── Accès avant/après installation ───────────────────────────────────────────

    public function test_install_accessible_avant_installation(): void
    {
        $this->get('/install')->assertOk();
    }

    public function test_install_inaccessible_apres_installation(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $this->get('/install')->assertStatus(404);
        $this->post('/install/token', ['token' => 'whatever'])->assertStatus(404);
        $this->post('/install', $this->payload())->assertStatus(404);
    }

    // ── Token ─────────────────────────────────────────────────────────────────────

    public function test_wizard_affiche_lecran_de_token_si_configure(): void
    {
        config(['app.install_token' => 'ma-cle-secrete']);

        $this->get('/install')->assertInertia(fn ($page) => $page->component('Install/Token'));
    }

    public function test_token_invalide_est_refuse(): void
    {
        config(['app.install_token' => 'ma-cle-secrete']);

        $this->post('/install/token', ['token' => 'mauvaise-cle'])
            ->assertSessionHasErrors('token');

        $this->assertNull(session('install_token_verified'));
    }

    public function test_token_valide_est_accepte_et_debloque_le_wizard(): void
    {
        config(['app.install_token' => 'ma-cle-secrete']);

        $this->post('/install/token', ['token' => 'ma-cle-secrete'])
            ->assertRedirect(route('install.show'));

        $this->withSession(['install_token_verified' => true])
            ->get('/install')
            ->assertInertia(fn ($page) => $page->component('Install/Wizard'));
    }

    public function test_store_refuse_sans_token_verifie_quand_configure(): void
    {
        config(['app.install_token' => 'ma-cle-secrete']);

        $this->post('/install', $this->payload())->assertStatus(403);
        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_store_accepte_avec_token_verifie_en_session(): void
    {
        config(['app.install_token' => 'ma-cle-secrete']);

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload())
            ->assertOk();

        $this->assertTrue(AppInstallation::isInstalled());
    }

    public function test_verify_token_refuse_si_non_configure_en_absence_de_token(): void
    {
        config(['app.install_token' => null]);

        $this->post('/install/token', ['token' => 'peu-importe'])->assertStatus(403);
    }

    // ── Installation complète ───────────────────────────────────────────────────

    public function test_installation_complete_cree_organisation_et_super_admin(): void
    {
        $this->post('/install', $this->payload())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Install/Success'));

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame('ELM Test', $org->name);

        $user = User::where('telephone', '+224622000000')->firstOrFail();
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue(Hash::check('Sup3r$ecretPwd', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_pays_est_determine_depuis_le_telephone(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['telephone' => '+33612345678'],
        ]))->assertOk();

        $user = User::where('telephone', '+33612345678')->firstOrFail();
        $this->assertSame('FR', $user->code_pays);
        $this->assertSame('France', $user->pays);
        $this->assertSame('+33', $user->code_phone_pays);
    }

    public function test_telephone_invalide_est_rejete(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['telephone' => 'pas-un-numero'],
        ]))->assertSessionHasErrors('admin.telephone');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_mots_de_passe_non_confirmes_sont_rejetes(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['password_confirmation' => 'AutreChose$99'],
        ]))->assertSessionHasErrors('admin.password');
    }

    public function test_categories_oui_options_non(): void
    {
        $this->post('/install', $this->payload([
            'catalogue' => ['categories' => true, 'options' => false],
        ]))->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertGreaterThan(0, Categorie::where('organization_id', $org->id)->count());
        $this->assertSame(0, OptionCatalogue::where('organization_id', $org->id)->count());
    }

    public function test_categories_non_options_oui(): void
    {
        $this->post('/install', $this->payload([
            'catalogue' => ['categories' => false, 'options' => true],
        ]))->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(0, Categorie::where('organization_id', $org->id)->count());
        $this->assertGreaterThan(0, OptionCatalogue::where('organization_id', $org->id)->count());
    }

    public function test_types_vehicule_oui(): void
    {
        $this->post('/install', $this->payload([
            'catalogue' => ['types_vehicule' => true],
        ]))->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(5, TypeVehicule::where('organization_id', $org->id)->count());
        $this->assertTrue(TypeVehicule::where('organization_id', $org->id)->where('nom', 'Minibus')->exists());
    }

    public function test_installation_sans_catalogue_ne_cree_aucun_type_vehicule(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(0, TypeVehicule::where('organization_id', $org->id)->count());
    }

    public function test_reutilise_organisation_existante_de_meme_nom_sans_dupliquer(): void
    {
        Organization::create(['name' => 'ELM Test', 'slug' => 'peu-importe', 'is_active' => true]);

        $this->post('/install', $this->payload())->assertOk();

        $this->assertSame(1, Organization::where('name', 'ELM Test')->count());
        $this->assertSame('peu-importe', Organization::where('name', 'ELM Test')->first()->slug);
    }

    public function test_genere_automatiquement_un_slug_pour_une_nouvelle_organisation(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $this->assertSame('elm-test', Organization::where('name', 'ELM Test')->firstOrFail()->slug);
    }

    public function test_refuse_si_lorganisation_a_deja_un_super_admin(): void
    {
        $org = Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $existing = User::factory()->create(['organization_id' => $org->id]);
        $existing->assignRole('super_admin');

        $this->post('/install', $this->payload())->assertSessionHasErrors('organisation.nom');

        $this->assertSame(1, User::where('organization_id', $org->id)->count());
        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_installed_at_nest_renseigne_quapres_succes_complet(): void
    {
        // Un téléphone invalide fait échouer l'installation avant la fin de la transaction.
        $this->post('/install', $this->payload([
            'admin' => ['telephone' => 'invalide'],
        ]));

        $this->assertFalse(AppInstallation::isInstalled());
        $this->assertDatabaseMissing('organizations', ['slug' => 'elm-test']);
    }

    // ── Résolution téléphone en direct (étape 2 du wizard) ────────────────────────

    public function test_resolve_phone_retourne_les_informations_pays(): void
    {
        $this->postJson('/install/phone-info', ['telephone' => '+224622000000'])
            ->assertOk()
            ->assertJsonPath('info.pays', 'Guinée')
            ->assertJsonPath('info.devise', 'GNF');
    }

    public function test_resolve_phone_retourne_null_pour_un_numero_invalide(): void
    {
        $this->postJson('/install/phone-info', ['telephone' => 'invalide'])
            ->assertOk()
            ->assertJsonPath('info', null);
    }
}
