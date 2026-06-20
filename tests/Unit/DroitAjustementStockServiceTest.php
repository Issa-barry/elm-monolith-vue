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

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');

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

    public function test_admin_peut_toujours_ajuster(): void
    {
        $this->assertTrue(
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

    public function test_manager_sans_site_affecte_ne_peut_pas_ajuster(): void
    {
        $this->droitManager(['peut_augmenter' => true, 'peut_diminuer' => true]);
        $this->assertFalse($this->service->canAjuster($this->managerUser(), $this->org->id));
    }

    public function test_manager_sur_site_non_dans_perimetre_ne_peut_pas_ajuster(): void
    {
        $siteAutorise = $this->site('Site Autorisé');
        $siteNonAutorise = $this->site('Site Non Autorisé');
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'agences_selectionnees', 'sites' => [$siteAutorise->id]]);

        $this->assertFalse(
            $this->service->canAjuster($this->managerUser($siteNonAutorise), $this->org->id)
        );
    }

    // ── canAugmenter / canDiminuer ────────────────────────────────────────────

    public function test_admin_peut_toujours_augmenter_et_diminuer(): void
    {
        $admin = $this->adminUser();
        $this->assertTrue($this->service->canAugmenter($admin, $this->org->id));
        $this->assertTrue($this->service->canDiminuer($admin, $this->org->id));
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

    public function test_admin_peut_ajuster_sur_nimporte_quel_site(): void
    {
        $site = $this->site();
        $admin = $this->adminUser();
        $this->assertTrue($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'augmenter'));
        $this->assertTrue($this->service->canAjusterSurSite($admin, $this->org->id, $site->id, 'diminuer'));
    }

    public function test_manager_toutes_agences_peut_augmenter_sur_son_site(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);
        $this->assertTrue(
            $this->service->canAjusterSurSite($this->managerUser($site), $this->org->id, $site->id, 'augmenter')
        );
    }

    public function test_manager_non_affecte_au_site_ne_peut_pas_ajuster(): void
    {
        $site = $this->site();
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);

        // Manager sans site affecté
        $this->assertFalse(
            $this->service->canAjusterSurSite($this->managerUser(), $this->org->id, $site->id, 'augmenter')
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

    public function test_admin_sites_autorises_retourne_null(): void
    {
        $this->assertNull($this->service->sitesAutorises($this->adminUser(), $this->org->id));
    }

    public function test_toutes_agences_sites_autorises_retourne_sites_de_utilisateur(): void
    {
        $siteA = $this->site('Site A');
        $siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);

        // Manager affecté à siteA seulement (pas siteB)
        $result = $this->service->sitesAutorises($this->managerUser($siteA), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertEquals($siteA->id, $result->first()->id);
    }

    public function test_agences_selectionnees_sites_autorises_retourne_intersection(): void
    {
        $siteA = $this->site('Site A');
        $siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'agences_selectionnees', 'sites' => [$siteA->id, $siteB->id]]);

        // Manager affecté à siteA seulement → intersection = [siteA]
        $result = $this->service->sitesAutorises($this->managerUser($siteA), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertEquals($siteA->id, $result->first()->id);
    }

    public function test_sans_droit_sites_autorises_retourne_collection_vide(): void
    {
        $site = $this->site();
        $result = $this->service->sitesAutorises($this->managerUser($site), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(0, $result);
    }

    public function test_manager_sans_site_affecte_sites_autorises_retourne_collection_vide(): void
    {
        $this->droitManager(['peut_augmenter' => true, 'perimetre' => 'toutes_agences']);
        $result = $this->service->sitesAutorises($this->managerUser(), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(0, $result);
    }
}
