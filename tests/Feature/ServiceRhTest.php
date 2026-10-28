<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ServiceRh;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Services RH : référentiel par organisation, permission `rh-referentiels.gerer`. */
class ServiceRhTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/backoffice/services-rh';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function utilisateur(Organization $org, array $permissions = ['rh-referentiels.gerer'], string $role = 'manager'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_l_ecran_exige_la_permission(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->utilisateur($org, []))->get(self::URL)->assertForbidden();
        $this->actingAs($this->utilisateur($org))->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ServicesRh/Index'));
    }

    public function test_la_creation_propose_un_code_et_reste_dans_l_organisation(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->utilisateur($org))
            ->post(self::URL, ['libelle' => 'Service commercial'])
            ->assertSessionHasNoErrors();

        $service = ServiceRh::firstOrFail();
        $this->assertSame($org->id, $service->organization_id);
        $this->assertSame('SC', $service->code);
        $this->assertTrue($service->is_active);
    }

    public function test_un_libelle_en_double_est_refuse_dans_la_meme_organisation_seulement(): void
    {
        $org = Organization::factory()->create();
        $autreOrg = Organization::factory()->create();
        ServiceRh::create(['organization_id' => $org->id, 'libelle' => 'Logistique', 'code' => 'LOG']);

        $this->actingAs($this->utilisateur($org))
            ->post(self::URL, ['libelle' => 'Logistique'])
            ->assertSessionHasErrors('libelle');

        $this->actingAs($this->utilisateur($autreOrg))
            ->post(self::URL, ['libelle' => 'Logistique'])
            ->assertSessionHasNoErrors();
    }

    public function test_la_creation_est_refusee_sans_permission(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->utilisateur($org, ['rh-employes.read']))
            ->post(self::URL, ['libelle' => 'Logistique'])
            ->assertForbidden();

        $this->assertDatabaseCount('services_rh', 0);
    }

    public function test_modifier_et_desactiver_puis_reactiver(): void
    {
        $org = Organization::factory()->create();
        $service = ServiceRh::create(['organization_id' => $org->id, 'libelle' => 'Logistique', 'code' => 'LOG']);
        $user = $this->utilisateur($org);

        $this->actingAs($user)->put(self::URL."/{$service->id}", ['libelle' => 'Logistique et transport'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Logistique et transport', $service->fresh()->libelle);

        $this->actingAs($user)->patch(self::URL."/{$service->id}/toggle");
        $this->assertFalse($service->fresh()->is_active);

        $this->actingAs($user)->patch(self::URL."/{$service->id}/toggle");
        $this->assertTrue($service->fresh()->is_active);
    }

    public function test_un_service_d_une_autre_organisation_est_inaccessible_meme_au_super_admin(): void
    {
        $org = Organization::factory()->create();
        $autreOrg = Organization::factory()->create();
        $service = ServiceRh::create(['organization_id' => $autreOrg->id, 'libelle' => 'Logistique', 'code' => 'LOG']);
        $superAdmin = $this->utilisateur($org, [], 'super_admin');

        $this->actingAs($superAdmin)->put(self::URL."/{$service->id}", ['libelle' => 'Piraté'])->assertForbidden();
        $this->actingAs($superAdmin)->patch(self::URL."/{$service->id}/toggle")->assertForbidden();

        $this->assertSame('Logistique', $service->fresh()->libelle);
        $this->assertTrue($service->fresh()->is_active);
    }
}
