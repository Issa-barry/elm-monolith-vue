<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransfertLogistiqueIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::LOGISTIQUE);

        return $org;
    }

    private function makeSite(Organization $org, string $nom): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(Organization $org): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'categorie' => 'interne',
            'is_active' => true,
        ]);
    }

    private function makeAdminUser(Organization $org, Site $site): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['logistique.create', 'logistique.read']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeNonAdminUser(Organization $org, Site $site): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['logistique.create', 'logistique.read']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function creerTransfert(Organization $org, Site $source, Site $dest, Vehicule $vehicule, User $user): TransfertLogistique
    {
        $this->actingAs($user);

        return TransfertLogistique::create([
            'organization_id' => $org->id,
            'site_source_id' => $source->id,
            'site_destination_id' => $dest->id,
            'vehicule_id' => $vehicule->id,
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_admin_filtre_par_site_depart(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin);
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin);

        $response = $this->actingAs($admin)
            ->get('/logistique/transferts?depart_site_ids[]='.$siteA->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
        );
    }

    public function test_admin_filtre_par_site_arrivee(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin);
        $this->creerTransfert($org, $siteA, $siteC, $vehicule, $admin);

        $response = $this->actingAs($admin)
            ->get('/logistique/transferts?arrivee_site_ids[]='.$siteB->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_destination_nom', 'Site B')
        );
    }

    public function test_admin_filtre_depart_et_arrivee_combines(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin); // doit rester
        $this->creerTransfert($org, $siteA, $siteC, $vehicule, $admin); // éliminé par arrivée
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin); // éliminé par départ

        $response = $this->actingAs($admin)
            ->get('/logistique/transferts?depart_site_ids[]='.$siteA->id.'&arrivee_site_ids[]='.$siteB->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
            ->where('transferts.0.site_destination_nom', 'Site B')
        );
    }

    public function test_non_admin_voit_seulement_transferts_de_son_site_depart(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);
        $nonAdmin = $this->makeNonAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin); // visible
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin); // invisible

        $response = $this->actingAs($nonAdmin)->get('/logistique/transferts');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
        );
    }

    public function test_non_admin_ne_peut_pas_forcer_un_autre_site_depart(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);
        $nonAdmin = $this->makeNonAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin); // son site
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin); // site interdit

        // Tente de forcer siteC via query params
        $response = $this->actingAs($nonAdmin)
            ->get('/logistique/transferts?depart_site_ids[]='.$siteC->id);

        $response->assertOk();
        // Doit voir seulement son propre site A, le param siteC est ignoré
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
        );
    }
}
