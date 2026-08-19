<?php

namespace Tests\Feature;

use App\Enums\DomaineActivite;
use App\Enums\SiteType;
use App\Models\AppInstallation;
use App\Models\Categorie;
use App\Models\OptionCatalogue;
use App\Models\Organization;
use App\Models\ProduitType;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Services\InstallationService;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    /**
     * Email par défaut de payload() — pré-vérifié dans setUp() pour que tous les tests qui
     * n'ont rien à voir avec la règle email (contenu du domaine, téléphone, mot de passe...)
     * n'aient pas à répéter le cycle OTP. Depuis que l'email est obligatoire en on_premise (mode
     * par défaut, cf. config/app.php), la quasi-totalité des posts /install en ont besoin.
     */
    private const DEFAULT_EMAIL = 'issa@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        // throttle:install (10/min/IP, cf. FortifyServiceProvider) est testé fonctionnellement
        // ailleurs (comportement métier) — le désactiver ici évite que le grand nombre de
        // requêtes de cette suite sur les mêmes routes /install* ne se percute lui-même.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->preVerifyEmail(self::DEFAULT_EMAIL);
    }

    private function preVerifyEmail(string $email): void
    {
        app(OtpService::class)->generate($email, InstallationService::EMAIL_OTP_CONTEXT);
        app(OtpService::class)->markVerified($email, InstallationService::EMAIL_OTP_CONTEXT);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'organisation' => ['nom' => 'ELM Test', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            'admin' => [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+224622000000',
                'email' => self::DEFAULT_EMAIL,
                'password' => 'Sup3r$ecretPwd',
                'password_confirmation' => 'Sup3r$ecretPwd',
            ],
            'site' => [
                'type' => SiteType::SIEGE->value,
                'ville' => 'Conakry',
                'quartier' => 'Matoto',
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

        // Deuxième admin distinct : email et téléphone doivent différer du premier
        // (user_auth_identities.normalized_value est unique GLOBALEMENT, pas par organisation —
        // cf. UserAuthIdentity). Un nouveau code doit aussi être vérifié pour cette adresse : le
        // précédent, lié à self::DEFAULT_EMAIL, a été consommé (clear()) par la 1ère installation.
        $this->preVerifyEmail('issa2@gmail.com');

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload([
                'organisation' => ['nom' => 'ELM Test 2'],
                'admin' => ['telephone' => '+224622000001', 'email' => 'issa2@gmail.com'],
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

        // Cf. commentaire de test_install_reste_accessible_apres_installation_en_saas : email et
        // téléphone distincts (identité globale, pas scopée par organisation), nouveau code requis.
        $this->preVerifyEmail('issa2@gmail.com');

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload([
                'admin' => ['telephone' => '+224622000001', 'email' => 'issa2@gmail.com'],
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

    /**
     * La détection pays/indicatif reste générique (PhoneCountryInfo/libphonenumber, cf.
     * resolveTelephone()) — seule l'installation elle-même restreint ensuite à Guinée/Sierra
     * Leone (cf. test_installation_refuse_un_numero_hors_guinee_sierra_leone), pas la résolution.
     */
    public function test_pays_est_determine_depuis_le_telephone(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['telephone' => '+224622000000'],
        ]))->assertOk();

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertSame('GN', $user->code_pays);
        $this->assertSame('Guinée', $user->pays);
        $this->assertSame('+224', $user->code_phone_pays);
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
                'email' => self::DEFAULT_EMAIL,
                'password' => 'Sup3r$ecretPwd',
            ],
            'site' => [
                'type' => SiteType::SIEGE->value,
                'ville' => 'Conakry',
                'quartier' => 'Matoto',
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

    public function test_email_est_persiste_et_marque_verifie_quand_le_code_a_ete_valide(): void
    {
        // example.com a un enregistrement MX "null" (RFC 7505, IANA) et est donc rejeté par la
        // règle `email:dns` du contrôleur — utiliser un domaine mail réel pour ce test.
        // (déjà pré-vérifié dans setUp() puisqu'il s'agit de self::DEFAULT_EMAIL)
        $this->post('/install', $this->payload())->assertOk();

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertSame(self::DEFAULT_EMAIL, $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    /**
     * "Email saisi ≠ email vérifié" (cf. InstallationService::install()) : sans passer par
     * sendEmailCode()/verifyEmailCode() au préalable, l'installation doit être refusée — jamais
     * de verified_at renseigné du seul fait d'avoir tapé une adresse dans le formulaire. Adresse
     * délibérément différente de self::DEFAULT_EMAIL (pré-vérifié dans setUp()).
     */
    public function test_installation_refusee_si_lemail_nest_jamais_ete_verifie(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['email' => 'jamais-verifie@gmail.com'],
        ]))->assertSessionHasErrors('admin.email');

        $this->assertFalse(AppInstallation::isInstalled());
        $this->assertDatabaseMissing('organizations', ['slug' => 'elm-test']);
    }

    // ── Règle email selon le mode de déploiement ──────────────────────────────────

    /**
     * En on_premise (mode par défaut, cf. config/app.php), l'email du Super Admin devient
     * obligatoire — contrairement au reste de l'application (Login, Register, invitations...),
     * qui n'est pas concerné par cette règle propre à /install.
     */
    public function test_on_premise_refuse_linstallation_sans_email(): void
    {
        config(['app.deployment_mode' => 'on_premise']);

        $this->post('/install', $this->payload([
            'admin' => ['email' => null],
        ]))->assertSessionHasErrors('admin.email');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_on_premise_refuse_un_email_au_format_invalide(): void
    {
        config(['app.deployment_mode' => 'on_premise']);

        $this->post('/install', $this->payload([
            'admin' => ['email' => 'pas-un-email'],
        ]))->assertSessionHasErrors('admin.email');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_on_premise_email_verifie_permet_de_terminer_linstallation(): void
    {
        config(['app.deployment_mode' => 'on_premise']);

        // self::DEFAULT_EMAIL est déjà pré-vérifié dans setUp().
        $this->post('/install', $this->payload())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Install/Success'));

        $this->assertTrue(AppInstallation::isInstalled());
        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_saas_installation_reussit_sans_email(): void
    {
        config(['app.deployment_mode' => 'saas', 'app.install_token' => 'ma-cle-secrete']);

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload(['admin' => ['email' => null]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Install/Success'));

        $this->assertTrue(AppInstallation::isInstalled());
        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertNull($user->email);
    }

    public function test_saas_avec_email_non_verifie_est_refuse(): void
    {
        config(['app.deployment_mode' => 'saas', 'app.install_token' => 'ma-cle-secrete']);

        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload(['admin' => ['email' => 'jamais-verifie@gmail.com']]))
            ->assertSessionHasErrors('admin.email');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_saas_avec_email_verifie_reussit(): void
    {
        config(['app.deployment_mode' => 'saas', 'app.install_token' => 'ma-cle-secrete']);

        // self::DEFAULT_EMAIL est déjà pré-vérifié dans setUp().
        $this->withSession(['install_token_verified' => true])
            ->post('/install', $this->payload())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Install/Success'));

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertSame(self::DEFAULT_EMAIL, $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    /**
     * Le label affiché ("Email *" vs "Email (facultatif)") dépend de isSaas() — transmis tel quel
     * au composant Vue, jamais recalculé indépendamment côté frontend (cf. Wizard.vue::isSaas).
     */
    public function test_le_wizard_expose_is_saas_au_frontend(): void
    {
        config(['app.deployment_mode' => 'on_premise']);
        $this->get('/install')->assertInertia(fn ($page) => $page
            ->component('Install/Wizard')
            ->where('isSaas', false)
        );

        config(['app.deployment_mode' => 'saas', 'app.install_token' => 'ma-cle-secrete']);
        $this->withSession(['install_token_verified' => true])
            ->get('/install')
            ->assertInertia(fn ($page) => $page
                ->component('Install/Wizard')
                ->where('isSaas', true)
            );
    }

    /**
     * Le sélecteur de pays de /install (PAYS_INSTALL dans Wizard.vue) restreint la saisie à
     * Guinée/Sierra Leone côté UI ; InstallationService::install() applique la même restriction
     * côté serveur (cf. TELEPHONE_PAYS_AUTORISES) pour qu'un appel direct à l'API ne puisse pas
     * la contourner. Un numéro sierra-léonais valide doit être accepté de bout en bout.
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

    public function test_installation_refuse_un_numero_hors_guinee_sierra_leone(): void
    {
        $this->post('/install', $this->payload([
            'admin' => ['telephone' => '+33612345678'],
        ]))->assertSessionHasErrors('admin.telephone');

        $this->assertFalse(AppInstallation::isInstalled());
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

    /**
     * Le premier site fait désormais partie intégrante de l'installation (cf. InstallationService::
     * install()) — plus d'état intermédiaire "organisation installée mais sans site exploitable".
     */
    public function test_le_site_principal_est_cree_pendant_linstallation(): void
    {
        $this->post('/install', $this->payload([
            'site' => ['type' => SiteType::USINE->value, 'ville' => 'Conakry', 'quartier' => 'Matoto'],
        ]))->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(1, $org->sites()->count());

        $site = $org->sites()->firstOrFail();
        $this->assertSame(SiteType::USINE, $site->type);
        $this->assertSame('Conakry', $site->ville);
        $this->assertSame('Matoto', $site->quartier);
        $this->assertSame('Usine de Matoto', $site->nom);
    }

    /**
     * Nom généré automatiquement ("{Type} de {Quartier}", cf. SiteNamingService) — jamais saisi
     * par l'utilisateur pendant l'installation.
     */
    public function test_le_nom_du_site_principal_est_genere_automatiquement(): void
    {
        $this->post('/install', $this->payload([
            'site' => ['type' => SiteType::BOUTIQUE->value, 'ville' => 'Conakry', 'quartier' => 'Sonfonia'],
        ]))->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $site = $org->sites()->firstOrFail();
        $this->assertSame('Boutique de Sonfonia', $site->nom);
    }

    /**
     * Téléphone et pays hérités du Super Admin qui vient d'être créé — jamais redemandés pendant
     * l'installation (cf. InstallationService::creerSite()).
     */
    public function test_le_site_principal_herite_du_telephone_et_du_pays_du_super_admin(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $site = $org->sites()->firstOrFail();

        $this->assertSame('+224622000000', $site->telephone);
        $this->assertSame('Guinée', $site->pays);
    }

    /**
     * Le Super Admin doit pouvoir utiliser immédiatement le site créé — sans ce rattachement,
     * `default_site` resterait vide côté frontend et require.site bloquerait sa première connexion.
     */
    public function test_le_super_admin_est_rattache_au_site_principal_comme_site_par_defaut(): void
    {
        $this->post('/install', $this->payload())->assertOk();

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $site = $org->sites()->firstOrFail();

        $pivot = $user->sites()->wherePivot('is_default', true)->first();
        $this->assertNotNull($pivot);
        $this->assertSame($site->id, $pivot->id);
    }

    public function test_le_type_de_site_est_obligatoire(): void
    {
        $this->post('/install', $this->payload([
            'site' => ['type' => ''],
        ]))->assertSessionHasErrors('site.type');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_un_type_de_site_invalide_est_rejete(): void
    {
        $this->post('/install', $this->payload([
            'site' => ['type' => 'pas-un-type'],
        ]))->assertSessionHasErrors('site.type');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_la_ville_du_site_principal_est_obligatoire(): void
    {
        $this->post('/install', $this->payload([
            'site' => ['ville' => ''],
        ]))->assertSessionHasErrors('site.ville');

        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_le_quartier_du_site_principal_est_obligatoire(): void
    {
        $this->post('/install', $this->payload([
            'site' => ['quartier' => ''],
        ]))->assertSessionHasErrors('site.quartier');

        $this->assertFalse(AppInstallation::isInstalled());
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
            site: ['type' => SiteType::SIEGE->value, 'ville' => 'Conakry', 'quartier' => 'Matoto'],
        );
        app(InstallationService::class)->install(
            organisation: ['nom' => 'Org B', 'domaine' => DomaineActivite::COMMERCE_DISTRIBUTION->value],
            admin: [
                'prenom' => 'Beta', 'nom' => 'B', 'telephone' => '+224622222222',
                'email' => null, 'password' => 'Sup3r$ecretPwd', 'password_confirmation' => 'Sup3r$ecretPwd',
            ],
            site: ['type' => SiteType::SIEGE->value, 'ville' => 'Conakry', 'quartier' => 'Matoto'],
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
            site: ['type' => SiteType::SIEGE->value, 'ville' => 'Conakry', 'quartier' => 'Matoto'],
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
