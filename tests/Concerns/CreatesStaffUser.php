<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait CreatesStaffUser
{
    protected function staffUser(Organization $org = null, string $role = 'admin_entreprise'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $org ??= Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);

        return $user;
    }

    protected function staffUserWithPermissions(Organization $org, array $permissions): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function clientUser(Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $org ??= Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        return $user;
    }

    protected function superAdminUser(Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org ??= Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        return $user;
    }
}
