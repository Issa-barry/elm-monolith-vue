<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VenteParametrageTest extends TestCase
{
    use RefreshDatabase;

    private function createRoles(): void
    {
        foreach (['super_admin', 'admin_entreprise', 'manager', 'commerciale'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    private function createAuthorizedUser(string $permission): User
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $adminRole = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user->assignRole($adminRole);

        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);

        return $user;
    }

    public function test_edit_exposes_price_permission_flags_per_role(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.read');

        $this->actingAs($user)
            ->get(route('settings.ventes.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Ventes')
                ->has('roles')
                ->where('roles', fn ($roles) => collect($roles)->every(
                    fn (array $role) => array_key_exists('can_update_prix_unitaire', $role)
                ))
            );
    }

    public function test_update_applies_unit_price_permission_by_role_selection(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');
        Permission::findOrCreate('parametres.read', 'web');
        $user->givePermissionTo('parametres.read');

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'commission_generation_mode' => Parametre::COMMISSION_MODE_COMMANDE_VALIDEE,
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => ['commerciale'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $commercialeRole = Role::query()->where('name', 'commerciale')->firstOrFail();
        $managerRole = Role::query()->where('name', 'manager')->firstOrFail();

        $this->assertTrue($commercialeRole->hasPermissionTo('ventes.prix.update'));
        $this->assertFalse($managerRole->hasPermissionTo('ventes.prix.update'));
    }
}
