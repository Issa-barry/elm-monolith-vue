<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardCommissionsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Matoto',
            'type' => 'agence',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_les_invites_sont_rediriges_vers_la_connexion(): void
    {
        $this->get(route('dashboard.commissions'))->assertRedirect(route('login'));
    }

    public function test_accessible_avec_la_lecture_comptabilite(): void
    {
        $this->actingAs($this->staff(['comptabilite.read']))
            ->get(route('dashboard.commissions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('DashboardCommissions'));
    }

    public function test_accessible_avec_la_lecture_commissions(): void
    {
        $this->actingAs($this->staff(['commissions.read']))
            ->get(route('dashboard.commissions'))
            ->assertOk();
    }

    public function test_refuse_sans_droit_de_lecture_des_commissions(): void
    {
        $this->actingAs($this->staff(['ventes.read']))
            ->get(route('dashboard.commissions'))
            ->assertForbidden();
    }
}
