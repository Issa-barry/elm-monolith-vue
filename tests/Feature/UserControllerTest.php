<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function superAdmin(Organization $org): User
    {
        $this->createRole('super_admin');
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function adminUser(Organization $org): User
    {
        $this->createRole('admin_entreprise');
        Permission::firstOrCreate(['name' => 'users.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('users.read');

        return $user;
    }

    private function validStoreData(array $overrides = []): array
    {
        return array_merge([
            'prenom' => 'Mamadou',
            'nom' => 'Barry',
            'email' => null,
            'telephone' => '+224620000001',
            'role' => 'manager',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertStatus(200);
    }

    public function test_index_returns_200_for_user_with_read_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->adminUser($org);

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertStatus(403);
    }

    public function test_index_only_returns_users_from_same_org(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $this->createRole('manager');
        $admin = $this->superAdmin($org1);

        $userSameOrg = User::factory()->create(['organization_id' => $org1->id]);
        $userSameOrg->assignRole('manager');

        $userOtherOrg = User::factory()->create(['organization_id' => $org2->id]);
        $userOtherOrg->assignRole('manager');

        $response = $this->actingAs($admin)
            ->get(route('users.index'));

        $response->assertStatus(200);
        $users = $response->original->getData()['page']['props']['users'];
        $ids = array_column($users, 'id');

        $this->assertContains($admin->id, $ids);
        $this->assertContains($userSameOrg->id, $ids);
        $this->assertNotContains($userOtherOrg->id, $ids);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->get(route('users.create'))
            ->assertStatus(200);
    }

    public function test_create_returns_403_for_non_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->adminUser($org);

        $this->actingAs($user)
            ->get(route('users.create'))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_user_and_redirects(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData())
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'nom' => 'BARRY',
            'organization_id' => $org->id,
        ]);
    }

    public function test_store_formats_prenom_as_title_case(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['prenom' => 'mamadou']));

        $this->assertDatabaseHas('users', ['prenom' => 'Mamadou']);
    }

    public function test_store_uppercases_nom(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['nom' => 'barry']));

        $this->assertDatabaseHas('users', ['nom' => 'BARRY']);
    }

    public function test_store_assigns_role_to_user(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['role' => 'manager']));

        $created = User::where('telephone', '+224620000001')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('manager'));
    }

    public function test_store_allows_null_email(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['email' => null]))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['telephone' => '+224620000001', 'email' => null]);
    }

    public function test_store_fails_without_telephone(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['telephone' => null]))
            ->assertSessionHasErrors('telephone');
    }

    public function test_store_fails_with_duplicate_telephone(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        User::factory()->create([
            'telephone' => '+224620000001',
            'organization_id' => $org->id,
        ]);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['telephone' => '+224620000001']))
            ->assertSessionHasErrors('telephone');
    }

    public function test_store_fails_with_duplicate_email(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        User::factory()->create([
            'email' => 'existing@example.com',
            'organization_id' => $org->id,
        ]);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['email' => 'existing@example.com']))
            ->assertSessionHasErrors('email');
    }

    public function test_store_fails_with_password_mismatch(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData([
                'password' => 'Password123',
                'password_confirmation' => 'Different123',
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_store_fails_with_invalid_role(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->post(route('users.store'), $this->validStoreData(['role' => 'client']))
            ->assertSessionHasErrors('role');
    }

    public function test_store_returns_403_for_non_super_admin(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $user = $this->adminUser($org);

        $this->actingAs($user)
            ->post(route('users.store'), $this->validStoreData())
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_super_admin(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->actingAs($admin)
            ->get(route('users.edit', $target))
            ->assertStatus(200);
    }

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $this->createRole('manager');
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);

        $org = Organization::factory()->create();
        $user = $this->adminUser($org);
        $user->givePermissionTo('users.update');

        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->actingAs($user)
            ->get(route('users.edit', $target))
            ->assertStatus(200);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_user_and_redirects(): void
    {
        $this->createRole('manager');
        $this->createRole('commerciale');
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);

        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $target = User::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000099',
        ]);
        $target->assignRole('manager');

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'prenom' => 'Fatoumata',
                'nom' => 'Bah',
                'email' => null,
                'telephone' => '+224620000099',
                'role' => 'commerciale',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'prenom' => 'Fatoumata',
            'nom' => 'BAH',
        ]);

        $target->refresh();
        $this->assertTrue($target->hasRole('commerciale'));
    }

    public function test_update_does_not_change_password_when_empty(): void
    {
        $this->createRole('manager');
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);

        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $target = User::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000098',
        ]);
        $target->assignRole('manager');
        $originalHash = $target->password;

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'prenom' => $target->prenom,
                'nom' => $target->nom,
                'email' => null,
                'telephone' => '+224620000098',
                'role' => 'manager',
                'password' => '',
                'password_confirmation' => '',
            ]);

        $this->assertSame($originalHash, $target->fresh()->password);
    }

    public function test_update_allows_same_telephone_for_same_user(): void
    {
        $this->createRole('manager');
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']);

        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $target = User::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000097',
        ]);
        $target->assignRole('manager');

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'prenom' => $target->prenom,
                'nom' => $target->nom,
                'email' => null,
                'telephone' => '+224620000097',
                'role' => 'manager',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('users.index'));
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_user_and_redirects(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->actingAs($admin)
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_destroy_returns_403_for_non_super_admin(): void
    {
        $this->createRole('manager');
        $org = Organization::factory()->create();
        $user = $this->adminUser($org);

        $target = User::factory()->create(['organization_id' => $org->id]);
        $target->assignRole('manager');

        $this->actingAs($user)
            ->delete(route('users.destroy', $target))
            ->assertStatus(403);
    }

    public function test_destroy_prevents_self_deletion(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->superAdmin($org);

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertStatus(403);
    }
}
