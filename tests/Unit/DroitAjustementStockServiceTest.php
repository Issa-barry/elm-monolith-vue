<?php

namespace Tests\Unit;

use App\Models\DroitAjustementStock;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\DroitAjustementStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DroitAjustementStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private DroitAjustementStockService $service;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DroitAjustementStockService;
        $this->org = Organization::factory()->create();
    }

    /**
     * Admin Entreprise ne bypasse plus rien dans ce service depuis le 2026-09-06 (cf. docblock
     * de DroitAjustementStockService) — cf. superAdminUser() pour le seul rôle réellement
     * bypassé.
     */
    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    private function superAdminUser(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** Crée un manager, éventuellement affecté aux sites donnés. */
    private function managerUser(Site ...$sites): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        foreach ($sites as $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => false]);
        }

        return $user;
    }

    private function site(string $nom = 'Agence Test'): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function droitManager(array $overrides = []): DroitAjustementStock
    {
        return DroitAjustementStock::create(array_merge([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_augmenter' => false,
            'peut_diminuer' => false,
        ], $overrides));
    }

    // ── canAjuster ────────────────────────────────────────────────────────────

    public function test_super_admin_peut_toujours_ajuster(): void
    {
        $this->assertTrue(
            $this->service->canAjuster($this->superAdminUser(), $this->org->id)
        );
    }

    public function test_admin_entreprise_sans_droit_ne_peut_pas_ajuster(): void
    {
        $this->assertFalse(
            $this->service->canAjuster($this->adminUser(), $this->org->id)
        );
    }

    public function test_manager_avec_droit_augmenter_peut_ajuster(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => true]);
        $this->assertTrue($this->service->canAjuster($this->managerUser($site), $this->org->id));
    }

    public function test_manager_avec_droit_diminuer_peut_ajuster(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_diminuer' => true]);
        $this->assertTrue($this->service->canAjuster($this->managerUser($site), $this->org->id));
    }

    public function test_manager_sans_droit_ne_peut_pas_ajuster(): void
    {
        $site = $this->site();
        $this->assertFalse(
            $this->service->canAjuster($this->managerUser($site), $this->org->id)
        );
    }

    public function test_manager_avec_les_deux_droits_inactifs_ne_peut_pas_ajuster(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => false, 'peut_diminuer' => false]);
        $this->assertFalse($this->service->canAjuster($this->managerUser($site), $this->org->id));
    }

    public function test_manager_sans_site_affecte_peut_quand_meme_ajuster(): void
    {
        $this->droitManager(['peut_augmenter' => true, 'peut_diminuer' => true]);
        $this->assertTrue($this->service->canAjuster($this->managerUser(), $this->org->id));
    }

    /**
     * canAjuster() est une capacité générale ("ce rôle a-t-il un droit actif quelque part"),
     * jamais spécifique à un site — le rattachement personnel de l'utilisateur n'entre en jeu
     * que dans canAjusterSurSite()/sitesAutorises() (cf. docblock de classe, décision 2026-09-07).
     */
    public function test_manager_avec_droit_agences_selectionnees_peut_ajuster_meme_hors_site_personnel(): void
    {
        $siteAutorise = $this->site('Site Autorisé');
        $siteNonAutorise = $this->site('Site Non Autorisé');
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'agences_selectionnees', 'sites' => [$siteAutorise->id]]);

        $this->assertTrue(
            $this->service->canAjuster($this->managerUser($siteNonAutorise), $this->org->id)
        );
    }

    // ── canAugmenter / canDiminuer ────────────────────────────────────────────

    public function test_super_admin_peut_toujours_augmenter_et_diminuer(): void
    {
        $admin = $this->superAdminUser();
        $this->assertTrue($this->service->canAugmenter($admin, $this->org->id));
        $this->assertTrue($this->service->canDiminuer($admin, $this->org->id));
    }

    public function test_admin_entreprise_sans_droit_ne_peut_ni_augmenter_ni_diminuer(): void
    {
        $admin = $this->adminUser();
        $this->assertFalse($this->service->canAugmenter($admin, $this->org->id));
        $this->assertFalse($this->service->canDiminuer($admin, $this->org->id));
    }

    public function test_manager_avec_droit_augmenter_uniquement(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => true, 'peut_diminuer' => false]);
        $user = $this->managerUser($site);
        $this->assertTrue($this->service->canAugmenter($user, $this->org->id));
        $this->assertFalse($this->service->canDiminuer($user, $this->org->id));
    }

    public function test_manager_avec_droit_diminuer_uniquement(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => false, 'peut_diminuer' => true]);
        $user = $this->managerUser($site);
        $this->assertFalse($this->service->canAugmenter($user, $this->org->id));
        $this->assertTrue($this->service->canDiminuer($user, $this->org->id));
    }

    // ── canAjusterSurSite ─────────────────────────────────────────────────────

    public function test_super_admin_peut_ajuster_sur_nimporte_quel_site(): void
    {
        $site = $this->site();
        $admin = $this->superAdminUser();
        $this->assertTrue($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'augmenter'));
        $this->assertTrue($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'diminuer'));
    }

    public function test_admin_entreprise_sans_droit_ne_peut_ajuster_sur_aucun_site(): void
    {
        $site = $this->site();
        $admin = $this->adminUser();
        $this->assertFalse($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'augmenter'));
        $this->assertFalse($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'diminuer'));
    }

    public function test_manager_toutes_agences_peut_augmenter_sur_son_site(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);
        $this->assertTrue(
            $this->service->canAjusterSurSite($this->managerUser($site), $this->org->id, $site->id, 'augmenter')
        );
    }

    /**
     * `toutes_agences` est un périmètre réellement organisation-wide (décision 2026-09-07) :
     * un manager configuré ainsi peut agir sur un site auquel il n'est personnellement pas
     * rattaché — le périmètre configuré fait seul autorité, jamais recoupé avec ses propres
     * sites (cf. docblock de classe). C'est ce même mécanisme, sans bypass dédié, qui permet à
     * admin_entreprise de conserver un périmètre entreprise complet une fois configuré ainsi
     * (cf. test_admin_entreprise_avec_droit_toutes_agences_peut_ajuster_sur_nimporte_quel_site).
     */
    public function test_manager_toutes_agences_peut_ajuster_meme_sans_etre_affecte_au_site(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);

        $this->assertTrue(
            $this->service->canAjusterSurSite($this->managerUser(), $this->org->id, $site->id, 'augmenter')
        );
    }

    /**
     * Restaure, sans bypass dédié, le comportement qu'admin_entreprise perdait en même temps que
     * le bypass isAdmin() : un périmètre entreprise complet une fois son droit configuré en
     * `toutes_agences` (cf. InstallationService::install() et les migrations de backfill qui
     * provisionnent exactement cette ligne pour toute organisation).
     */
    public function test_admin_entreprise_avec_droit_toutes_agences_peut_ajuster_sur_nimporte_quel_site(): void
    {
        $site = $this->site();
        $admin = $this->adminUser();
        DroitAjustementStock::create([
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
            'perimetre' => 'toutes_agences',
            'peut_augmenter' => true,
            'peut_diminuer' => true,
        ]);

        $this->assertTrue($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'augmenter'));
        $this->assertTrue($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'diminuer'));
        $this->assertNull($this->service->sitesAutorises($admin, $this->org->id));
    }

    /**
     * Le périmètre `agences_selectionnees` fait lui aussi seul autorité — un manager peut agir
     * sur un site explicitement listé même sans y être personnellement rattaché.
     */
    public function test_manager_agences_selectionnees_peut_augmenter_meme_sans_etre_affecte(): void
    {
        $siteA = $this->site('Site A');
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'agences_selectionnees', 'sites' => [$siteA->id]]);

        $this->assertTrue(
            $this->service->canAjusterSurSite($this->managerUser(), $this->org->id, $siteA->id, 'augmenter')
        );
    }

    /**
     * Isolation organisationnelle : même avec un droit `toutes_agences` valide dans son
     * organisation, un utilisateur ne peut jamais agir sur un site d'une AUTRE organisation.
     * Verrouille le garde-fou défensif de canAjusterSurSite() (vérification Site::organization_id)
     * ajouté le 2026-09-07 — sans lui, `toutes_agences` répondrait vrai sans jamais vérifier que
     * le site appartient bien à l'organisation de l'appelant.
     */
    public function test_toutes_agences_ne_donne_pas_acces_a_un_site_dune_autre_organisation(): void
    {
        $manager = $this->managerUser();
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);

        $autreOrg = Organization::factory()->create();
        $siteAutreOrg = Site::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Site externe',
            'type' => 'depot',
            'localisation' => 'Dakar',
        ]);

        $this->assertFalse(
            $this->service->canAjusterSurSite($manager, $this->org->id, $siteAutreOrg->id, 'augmenter')
        );
    }

    public function test_manager_agences_selectionnees_peut_augmenter_sur_site_autorise(): void
    {
        $siteA = $this->site('Site A');
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'agences_selectionnees', 'sites' => [$siteA->id]]);
        $this->assertTrue(
            $this->service->canAjusterSurSite($this->managerUser($siteA), $this->org->id, $siteA->id, 'augmenter')
        );
    }

    public function test_manager_ne_peut_pas_augmenter_sur_site_non_autorise(): void
    {
        $siteA = $this->site('Site A');
        $siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kankan']);
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'agences_selectionnees', 'sites' => [$siteA->id]]);

        // Manager affecté à siteA et siteB, mais le droit ne couvre que siteA
        $this->assertFalse(
            $this->service->canAjusterSurSite($this->managerUser($siteA, $siteB), $this->org->id, $siteB->id, 'augmenter')
        );
    }

    public function test_manager_avec_droit_augmenter_ne_peut_pas_diminuer(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => true, 'peut_diminuer' => false, 'perimetre' => 'toutes_agences']);
        $this->assertFalse(
            $this->service->canAjusterSurSite($this->managerUser($site), $this->org->id, $site->id, 'diminuer')
        );
    }

    public function test_manager_sans_droit_ne_peut_ajuster_sur_aucun_site(): void
    {
        $site = $this->site();
        $this->assertFalse($this->service->canAjusterSurSite($this->managerUser($site), $this->org->id, $site->id, 'augmenter'));
        $this->assertFalse($this->service->canAjusterSurSite($this->managerUser($site), $this->org->id, $site->id, 'diminuer'));
    }

    // ── sitesAutorises ────────────────────────────────────────────────────────

    public function test_super_admin_sites_autorises_retourne_null(): void
    {
        $this->assertNull($this->service->sitesAutorises($this->superAdminUser(), $this->org->id));
    }

    public function test_admin_entreprise_sans_droit_sites_autorises_retourne_collection_vide(): void
    {
        $result = $this->service->sitesAutorises($this->adminUser(), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(0, $result);
    }

    /**
     * `toutes_agences` retourne null (= toutes les agences de l'organisation, cf. convention
     * déjà utilisée par les appelants `?? $allSites`) — jamais restreint aux sites personnels de
     * l'utilisateur (décision 2026-09-07, cf. docblock de classe).
     */
    public function test_toutes_agences_sites_autorises_retourne_null(): void
    {
        $siteA = $this->site('Site A');
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);

        // Manager affecté à siteA seulement — sans incidence, toutes_agences n'est jamais recoupé.
        $result = $this->service->sitesAutorises($this->managerUser($siteA), $this->org->id);
        $this->assertNull($result);
    }

    /**
     * `agences_selectionnees` retourne exactement la liste configurée, même les sites auxquels
     * l'utilisateur n'est pas personnellement rattaché.
     */
    public function test_agences_selectionnees_sites_autorises_retourne_la_liste_configuree(): void
    {
        $siteA = $this->site('Site A');
        $siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'agences_selectionnees', 'sites' => [$siteA->id, $siteB->id]]);

        // Manager affecté à siteA seulement → la liste configurée reste [siteA, siteB] en entier.
        $result = $this->service->sitesAutorises($this->managerUser($siteA), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(2, $result);
    }

    public function test_sans_droit_sites_autorises_retourne_collection_vide(): void
    {
        $site = $this->site();
        $result = $this->service->sitesAutorises($this->managerUser($site), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(0, $result);
    }

    public function test_manager_sans_site_affecte_toutes_agences_sites_autorises_retourne_null(): void
    {
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);
        $result = $this->service->sitesAutorises($this->managerUser(), $this->org->id);
        $this->assertNull($result);
    }
}
