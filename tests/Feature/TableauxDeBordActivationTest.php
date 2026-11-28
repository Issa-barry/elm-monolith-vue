<?php

namespace Tests\Feature;

use App\Features\TableauDeBordFeature;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Commission\TableauDeBordCommissionsService;
use App\Services\Depenses\TableauDeBordDepensesService;
use App\Services\Rh\TableauDeBordRhService;
use App\Services\Stock\TableauDeBordStockService;
use App\Services\TableauDeBordService;
use App\Services\Vehicules\TableauDeBordVehiculesService;
use App\Support\AuthRedirects;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Activation des tableaux de bord « Stat- » par organisation (ADR 0071,
 * docs/tableaux-de-bord-activation.md).
 */
class TableauxDeBordActivationTest extends TestCase
{
    use RefreshDatabase;

    /** Routes refusées quand leur tableau est désactivé (Stat-Ventes, page d'accueil, à part). */
    private const ROUTES = [
        'commissions' => [TableauDeBordFeature::COMMISSIONS, 'dashboard.commissions', TableauDeBordCommissionsService::class],
        'depenses' => [TableauDeBordFeature::DEPENSES, 'dashboard.depenses', TableauDeBordDepensesService::class],
        'stock' => [TableauDeBordFeature::STOCK, 'dashboard.stock', TableauDeBordStockService::class],
        'rh' => [TableauDeBordFeature::RH, 'dashboard.rh', TableauDeBordRhService::class],
        'vehicules' => [TableauDeBordFeature::VEHICULES, 'dashboard.vehicules', TableauDeBordVehiculesService::class],
    ];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
    }

    /** @return array<string, array{0: string, 1: string, 2: class-string}> */
    public static function routes(): array
    {
        return self::ROUTES;
    }

    private function utilisateur(?Organization $org = null, string $role = 'super_admin', array $permissions = []): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Agence '.$org->id, 'type' => 'depot']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /** @return array<string, bool> */
    private function etats(Organization $org): array
    {
        return TableauDeBordService::allForOrg($org);
    }

    // ── Valeurs par défaut et persistance ──────────────────────────────────────────────────

    public function test_une_organisation_neuve_n_a_que_stat_ventes_et_stat_stock_actives(): void
    {
        $this->assertSame([
            TableauDeBordFeature::VENTES => true,
            TableauDeBordFeature::COMMISSIONS => false,
            TableauDeBordFeature::DEPENSES => false,
            TableauDeBordFeature::STOCK => true,
            TableauDeBordFeature::RH => false,
            TableauDeBordFeature::VEHICULES => false,
        ], $this->etats($this->org));
    }

    public function test_la_valeur_par_defaut_est_enregistree_en_base_des_la_premiere_lecture(): void
    {
        $this->etats($this->org);

        foreach (TableauDeBordFeature::ALL as $tableau) {
            $this->assertDatabaseHas('features', [
                'name' => $tableau,
                'scope' => Feature::serializeScope($this->org),
                'value' => json_encode(TableauDeBordFeature::defaultState($tableau)),
            ]);
        }
    }

    public function test_les_choix_de_l_administrateur_survivent_a_un_redeploiement(): void
    {
        TableauDeBordService::definir(TableauDeBordFeature::VEHICULES, $this->org, true);
        TableauDeBordService::definir(TableauDeBordFeature::STOCK, $this->org, false);

        // Ce que fait le pipeline de déploiement (deploy-hostinger-*.yml), puis vidage des caches.
        Artisan::call('migrate', ['--force' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Artisan::call('cache:clear');
        Feature::flushCache();

        $etats = $this->etats($this->org->fresh());
        $this->assertTrue($etats[TableauDeBordFeature::VEHICULES]);
        $this->assertFalse($etats[TableauDeBordFeature::STOCK]);
        $this->assertTrue($etats[TableauDeBordFeature::VENTES]);
        $this->assertFalse($etats[TableauDeBordFeature::RH]);
    }

    public function test_le_choix_persiste_apres_vidage_du_cache_et_nouvelle_connexion(): void
    {
        $admin = $this->utilisateur();
        $this->actingAs($admin)
            ->patch(route('settings.tableaux-de-bord.update'), ['tableau' => TableauDeBordFeature::RH, 'actif' => true])
            ->assertSessionHasNoErrors();

        Artisan::call('cache:clear');
        Feature::flushCache();
        $this->app['auth']->forgetGuards();

        $autre = $this->utilisateur();
        $this->actingAs($autre)->get(route('dashboard.rh'))->assertOk();
        $this->actingAs($autre)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('tableaux_de_bord.rh', true));
    }

    // ── Accès direct par URL ───────────────────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function test_un_tableau_desactive_est_refuse_par_url_sans_aucun_calcul(string $tableau, string $route, string $service): void
    {
        TableauDeBordService::definir($tableau, $this->org, false);
        $this->mock($service, function ($mock) {
            $mock->shouldNotReceive('calculer');
            $mock->shouldNotReceive('agencesProposees');
        });

        $this->actingAs($this->utilisateur())->get(route($route))->assertForbidden();
    }

    #[DataProvider('routes')]
    public function test_un_tableau_reactive_redevient_accessible(string $tableau, string $route): void
    {
        $admin = $this->utilisateur();
        TableauDeBordService::definir($tableau, $this->org, false);
        $this->actingAs($admin)->get(route($route))->assertForbidden();

        TableauDeBordService::definir($tableau, $this->org, true);
        $this->actingAs($admin)->get(route($route))->assertOk();
    }

    #[DataProvider('routes')]
    public function test_un_tableau_active_exige_toujours_la_permission_individuelle(string $tableau, string $route): void
    {
        TableauDeBordService::definir($tableau, $this->org, true);

        $this->actingAs($this->utilisateur(role: 'manager'))->get(route($route))->assertForbidden();
    }

    public function test_l_activation_ne_vaut_que_pour_son_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        TableauDeBordService::definir(TableauDeBordFeature::VEHICULES, $autreOrg, true);

        $this->actingAs($this->utilisateur())->get(route('dashboard.vehicules'))->assertForbidden();
        $this->actingAs($this->utilisateur($autreOrg))->get(route('dashboard.vehicules'))->assertOk();
        $this->assertFalse($this->etats($this->org)[TableauDeBordFeature::VEHICULES]);
    }

    // ── Stat-Ventes : la page d'accueil reste ─────────────────────────────────────────────

    public function test_stat_ventes_desactive_garde_la_page_d_accueil_sans_aucun_calcul_de_ventes(): void
    {
        $admin = $this->utilisateur();
        TableauDeBordService::definir(TableauDeBordFeature::VENTES, $this->org, false);

        DB::enableQueryLog();
        // Une période même invalide n'est plus lue : aucune redirection, aucune erreur.
        $this->actingAs($admin)->get(route('dashboard', ['periode' => 'inconnue']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('ventes_actif', false)
                ->has('qr_payload')
                ->missing('stats_factures')
                ->missing('evolution')
                ->missing('ca_par_site')
                ->missing('periode')
                ->where('tableaux_de_bord.ventes', false));

        $tables = (new FactureVente)->getTable();
        $requetesVentes = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], $tables));
        $this->assertCount(0, $requetesVentes, 'Aucune requête sur les factures de vente quand Stat-Ventes est désactivé.');
    }

    public function test_stat_ventes_desactive_ne_change_ni_la_connexion_ni_l_onboarding(): void
    {
        $admin = $this->utilisateur();
        TableauDeBordService::definir(TableauDeBordFeature::VENTES, $this->org, false);

        $this->assertSame(route('dashboard'), AuthRedirects::defaultPathForUser($admin));
        $this->assertSame('/backoffice/dashboard', config('fortify.home'));
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_stat_ventes_active_envoie_ses_indicateurs(): void
    {
        $this->actingAs($this->utilisateur())->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ventes_actif', true)
                ->has('stats_factures')
                ->has('periode'));
    }

    // ── Menu (props partagées) ─────────────────────────────────────────────────────────────

    public function test_le_menu_recoit_l_etat_de_chaque_tableau_de_bord(): void
    {
        $admin = $this->utilisateur();
        TableauDeBordService::definir(TableauDeBordFeature::DEPENSES, $this->org, true);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('tableaux_de_bord', [
                'ventes' => true,
                'commissions' => false,
                'depenses' => true,
                'stock' => true,
                'rh' => false,
                'vehicules' => false,
            ]));
    }

    // ── Paramètres → Tableaux de bord ──────────────────────────────────────────────────────

    public function test_la_page_de_parametres_liste_les_six_tableaux_dans_l_ordre_du_menu(): void
    {
        $this->actingAs($this->utilisateur())->get(route('settings.tableaux-de-bord.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/TableauxDeBord')
                ->has('tableaux', 6)
                ->where('tableaux.0.cle', TableauDeBordFeature::VENTES)
                ->where('tableaux.0.libelle', 'Stat-Ventes')
                ->where('tableaux.0.actif', true)
                ->where('tableaux.5.cle', TableauDeBordFeature::VEHICULES)
                ->where('tableaux.5.actif', false)
                ->has('tableaux.0.description'));
    }

    public function test_un_administrateur_active_puis_desactive_un_tableau(): void
    {
        $admin = $this->utilisateur(role: 'admin_entreprise', permissions: ['parametres.update']);

        $this->actingAs($admin)
            ->patch(route('settings.tableaux-de-bord.update'), ['tableau' => TableauDeBordFeature::VEHICULES, 'actif' => true])
            ->assertRedirect()
            ->assertSessionHas('success', 'Stat-Véhicules activé.');
        $this->assertTrue($this->etats($this->org)[TableauDeBordFeature::VEHICULES]);

        $this->actingAs($admin)
            ->patch(route('settings.tableaux-de-bord.update'), ['tableau' => TableauDeBordFeature::VEHICULES, 'actif' => false])
            ->assertSessionHas('success', 'Stat-Véhicules désactivé.');
        $this->assertFalse($this->etats($this->org)[TableauDeBordFeature::VEHICULES]);
    }

    public function test_la_modification_ne_touche_que_l_organisation_de_l_administrateur(): void
    {
        $autreOrg = Organization::factory()->create();
        $admin = $this->utilisateur(role: 'admin_entreprise', permissions: ['parametres.update']);

        $this->actingAs($admin)
            ->patch(route('settings.tableaux-de-bord.update'), ['tableau' => TableauDeBordFeature::RH, 'actif' => true]);

        $this->assertTrue($this->etats($this->org)[TableauDeBordFeature::RH]);
        $this->assertFalse($this->etats($autreOrg)[TableauDeBordFeature::RH]);
    }

    public function test_sans_parametres_update_la_page_et_la_modification_sont_refusees(): void
    {
        $manager = $this->utilisateur(role: 'manager', permissions: ['vehicules.read']);

        $this->actingAs($manager)->get(route('settings.tableaux-de-bord.edit'))->assertForbidden();
        $this->actingAs($manager)
            ->patch(route('settings.tableaux-de-bord.update'), ['tableau' => TableauDeBordFeature::VEHICULES, 'actif' => true])
            ->assertForbidden();
        $this->assertFalse($this->etats($this->org)[TableauDeBordFeature::VEHICULES]);
    }

    public function test_un_tableau_inconnu_ou_un_etat_invalide_est_refuse(): void
    {
        $admin = $this->utilisateur(role: 'admin_entreprise', permissions: ['parametres.update']);

        $this->actingAs($admin)
            ->patch(route('settings.tableaux-de-bord.update'), ['tableau' => 'module.ventes', 'actif' => false])
            ->assertSessionHasErrors('tableau');
        $this->actingAs($admin)
            ->patch(route('settings.tableaux-de-bord.update'), ['tableau' => TableauDeBordFeature::STOCK, 'actif' => 'peut-etre'])
            ->assertSessionHasErrors('actif');
        $this->assertTrue($this->etats($this->org)[TableauDeBordFeature::STOCK]);
    }
}
