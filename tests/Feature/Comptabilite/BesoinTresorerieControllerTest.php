<?php

namespace Tests\Feature\Comptabilite;

use App\Features\ModuleFeature;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class BesoinTresorerieControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
    }

    public function test_index_retourne_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Tresorerie/Index')
                ->has('rows')
                ->has('total_general')
            );
    }

    public function test_index_redirige_non_authentifie(): void
    {
        $this->get(route('comptabilite.tresorerie.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_refuse_sans_permission(): void
    {
        $this->user->syncPermissions([]);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.index'))
            ->assertStatus(403);
    }

    public function test_show_sans_agence_retourne_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.show', 'sans-agence'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Tresorerie/Show')
                ->where('site.id', null)
            );
    }

    public function test_show_agence_retourne_200(): void
    {
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.show', $site->id))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Tresorerie/Show')
                ->where('site.id', $site->id)
            );
    }

    public function test_show_refuse_agence_non_accessible_pour_non_admin(): void
    {
        $ownSite = $this->user->sites()->first();
        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Site',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'comptabilite.read', 'guard_name' => 'web']);
        $nonAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $nonAdmin->assignRole('manager');
        $nonAdmin->givePermissionTo('comptabilite.read');
        $nonAdmin->sites()->attach($ownSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($nonAdmin)
            ->get(route('comptabilite.tresorerie.show', $autreSite->id))
            ->assertStatus(403);
    }
}
