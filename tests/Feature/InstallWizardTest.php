<?php

namespace Tests\Feature;

use App\Enums\DomaineActivite;
use App\Models\AppInstallation;
use App\Models\Categorie;
use App\Models\OptionCatalogue;
use App\Models\Organization;
use App\Models\ProduitType;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Services\InstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
            'organisation' => ['nom' => 'ELM Test', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            'admin' => [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+224622000000',
                'email' => null,
                'password' => 'Sup3r$ecretPwd',
                'password_confirmation' => 'Sup3r$ecretPwd',
            ],
        ], $overrides);
    }

    // ── Accès avant/après installation ───────────────────────────────────────────

    public function test_install_accessible_avant_installation(): void
    {
        $this->get('/install')->assertOk();
    }

    public function test_install_redirige_vers_login_apres_installation_en_on_premise(): void
    {
        config(['app.deployment_mode' => 'on_premise']);

        $this->post('/install', $this->payload())->assertOk();

        $this->get('/install')->assertRedirect(route('login'));
        $this->post('/install/token', ['token' => 'whatever'])->assertStatus(404);
        $this->post('/install', $this->payload())->assertStatus(404);
    }

    public function test_install_reste_accessible_apres_installation_en_saas(): void
    {
        config(['app.deployment_mode' => 'saas', 'app.install_token' => 'ma-cle-secrete']);

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload())
            ->assertOk();

        $this->get('/install')->assertInertia(fn ($page) => $page->component('Install/Token'));

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload([
                'organisation' => ['nom' => 'ELM Test 2'],
                'admin' => ['telephone' => '+224622000001'],
            ]))
            ->assertOk();

        $this->assertSame(2, Organization::whereIn('name', ['ELM Test', 'ELM Test 2'])->count());
        $this->assertSame(2, AppInstallation::count());
    }

    /**
     * Le nom ne doit jamais servir d'identité technique en SaaS — deux entreprises indépendantes
     * peuvent légitimement porter le même nom commercial (cf. mémo idempotence corrigé).
     */
    public function test_deux_organisations_saas_peuvent_porter_le_meme_nom(): void
    {
        config(['app.deployment_mode' => 'saas', 'app.install_token' => 'ma-cle-secrete']);

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload())
            ->assertOk();

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload([
                'admin' => ['telephone' => '+224622000001'],
            ]))
            ->assertOk();

        $this->assertSame(2, Organization::where('name', 'ELM Test')->count());
        $this->assertSame(
            2,
            Organization::where('name', 'ELM Test')->pluck('id')->unique()->count(),
            'les deux organisations doivent avoir un identifiant distinct'
        );
    }

    public function test_install_saas_sans_token_configure_refuse_avec_erreur_serveur(): void
    {
        config(['app.deployment_mode' => 'saas', 'app.install_token' => null]);

        $this->get('/install')->assertStatus(500);
        $this->post('/install', $this->payload())->assertStatus(500);
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

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue(Hash::check('Sup3r$ecretPwd', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_pays_est_determine_depuis_le_telephone(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['telephone' => '+33612345678'],
        ]))->assertOk();

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+33612345678'))->firstOrFail();
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

    /**
     * Le formulaire /install ne comporte plus de champ "Confirmer le mot de passe" (installation
     * plus rapide, saisie une seule fois) — le serveur ne doit pas l'exiger non plus.
     */
    public function test_installation_reussit_sans_champ_password_confirmation(): void
    {
        $payload = [
            'organisation' => ['nom' => 'ELM Test', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            'admin' => [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+224622000000',
                'email' => null,
                'password' => 'Sup3r$ecretPwd',
            ],
        ];

        $this->post('/install', $payload)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Install/Success'));

        $this->assertTrue(AppInstallation::isInstalled());
    }

    public function test_mot_de_passe_trop_faible_est_rejete(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['password' => 'faible', 'password_confirmation' => 'faible'],
        ]))->assertSessionHasErrors('admin.password');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_email_est_persiste_quand_fourni(): void
    {
        // example.com a un enregistrement MX "null" (RFC 7505, IANA) et est donc rejeté par la
        // règle `email:dns` du contrôleur — utiliser un domaine mail réel pour ce test.
        $this->post('/install', $this->payload([
            'admin' => ['email' => 'issa@gmail.com'],
        ]))->assertOk();

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertSame('issa@gmail.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    /**
     * Le sélecteur de pays de /install (PAYS_INSTALL dans Wizard.vue) restreint la saisie à
     * Guinée/Sierra Leone côté UI, mais la résolution serveur (PhoneCountryInfo, libphonenumber)
     * reste générique — un numéro sierra-léonais valide doit être accepté de bout en bout.
     */
    public function test_installation_reussit_avec_un_numero_sierra_leonais(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['telephone' => '+23276123456'],
        ]))->assertOk();

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+23276123456'))->firstOrFail();
        $this->assertSame('SL', $user->code_pays);
        $this->assertSame('Sierra Leone', $user->pays);
        $this->assertSame('+232', $user->code_phone_pays);
    }

    public function test_catalogue_par_defaut_est_toujours_cree(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertGreaterThan(0, Categorie::where('organization_id', $org->id)->count());
        $this->assertGreaterThan(0, OptionCatalogue::where('organization_id', $org->id)->count());
        $this->assertSame(5, TypeVehicule::where('organization_id', $org->id)->count());
        $this->assertTrue(TypeVehicule::where('organization_id', $org->id)->where('nom', 'Minibus')->exists());
        $this->assertTrue(ProduitType::where('organization_id', $org->id)->where('code', 'matiere_production')->exists());
    }

    public function test_aucun_site_nest_cree_pendant_linstallation(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(0, $org->sites()->count());
    }

    /**
     * Propriétaire interne par défaut (véhicules "interne", commissions propriétaire) créé et
     * rattaché à l'organisation dès l'installation — plus jamais deviné depuis un numéro de
     * téléphone codé en dur (cf. Organization::proprietaireInterne(), Proprietaire::interneParDefautId()).
     */
    public function test_propriétaire_interne_est_cree_et_rattache_a_lorganisation(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();

        $this->assertNotNull($org->proprietaire_interne_id);

        $proprietaireInterne = $org->proprietaireInterne;
        $this->assertNotNull($proprietaireInterne);
        $this->assertSame($user->id, $proprietaireInterne->user_id);
        $this->assertSame($user->nom, $proprietaireInterne->nom);
        $this->assertSame($user->prenom, $proprietaireInterne->prenom);
        $this->assertSame($user->telephone, $proprietaireInterne->telephone);
        $this->assertSame($org->id, $proprietaireInterne->organization_id);
    }

    /**
     * Deux organisations installées séparément ont chacune leur propre propriétaire interne —
     * jamais partagé entre organisations (cf. Organization::proprietaire_interne_id, scoping
     * strict par organization_id).
     */
    public function test_propriétaire_interne_nest_jamais_partage_entre_organisations(): void
    {
        config(['app.deployment_mode' => 'saas']);

        app(InstallationService::class)->install(
            organisation: ['nom' => 'Org A', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            admin: [
                'prenom' => 'Alpha', 'nom' => 'A', 'telephone' => '+224622111111',
                'email' => null, 'password' => 'Sup3r$ecretPwd', 'password_confirmation' => 'Sup3r$ecretPwd',
            ],
        );
        app(InstallationService::class)->install(
            organisation: ['nom' => 'Org B', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            admin: [
                'prenom' => 'Beta', 'nom' => 'B', 'telephone' => '+224622222222',
                'email' => null, 'password' => 'Sup3r$ecretPwd', 'password_confirmation' => 'Sup3r$ecretPwd',
            ],
        );

        $orgA = Organization::where('slug', 'org-a')->firstOrFail();
        $orgB = Organization::where('slug', 'org-b')->firstOrFail();

        $this->assertNotSame($orgA->proprietaire_interne_id, $orgB->proprietaire_interne_id);
        $this->assertSame($orgA->id, $orgA->proprietaireInterne->organization_id);
        $this->assertSame($orgB->id, $orgB->proprietaireInterne->organization_id);
    }

    public function test_domaine_dactivite_est_persiste(): void
    {
        $this->post('/install', $this->payload([
            'organisation' => ['domaine' => DomaineActivite::RESTAURATION->value],
        ]))->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(DomaineActivite::RESTAURATION, $org->domaine_activite);

        // Les catégories seedées reflètent le domaine choisi (Plats, pas Vêtements).
        $this->assertTrue(Categorie::where('organization_id', $org->id)->where('nom', 'Plats')->exists());
        $this->assertFalse(Categorie::where('organization_id', $org->id)->where('nom', 'Vêtements')->exists());
    }

    public function test_domaine_dactivite_obligatoire(): void
    {
        $this->post('/install', $this->payload([
            'organisation' => ['domaine' => ''],
        ]))->assertSessionHasErrors('organisation.domaine');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_domaine_dactivite_invalide_est_rejete(): void
    {
        $this->post('/install', $this->payload([
            'organisation' => ['domaine' => 'pas-un-domaine'],
        ]))->assertSessionHasErrors('organisation.domaine');
    }

    public function test_refuse_une_seconde_organisation_en_on_premise(): void
    {
        config(['app.deployment_mode' => 'on_premise']);

        $this->post('/install', $this->payload())->assertOk();

        // Le verrou web (isLocked → redirect login) ferme déjà /install normalement — ce test
        // vérifie le filet de sécurité métier dans InstallationService lui-même, en simulant un
        // appel qui contournerait isLocked() (ex: depuis la CLI, cf. InstallAppTest).
        $service = app(InstallationService::class);

        $this->expectException(ValidationException::class);

        $service->install(
            organisation: ['nom' => 'Autre Entreprise', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            admin: [
                'prenom' => 'Issa', 'nom' => 'BARRY', 'telephone' => '+224622000099', 'email' => null,
                'password' => 'Sup3r$ecretPwd', 'password_confirmation' => 'Sup3r$ecretPwd',
            ],
        );
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
