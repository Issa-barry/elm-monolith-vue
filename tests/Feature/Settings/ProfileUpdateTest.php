<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'prenom' => 'Test',
                'nom' => 'User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test', $user->prenom);
        $this->assertSame('USER', $user->nom);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'prenom' => 'Test',
                'nom' => 'User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_super_admin_can_update_telephone(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'prenom' => 'Test',
                'nom' => 'User',
                'email' => $user->email,
                'telephone' => '+224620000001',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $identity = $user->fresh()->telephoneIdentity();
        $this->assertNotNull($identity);
        $this->assertSame('+224620000001', $identity->value);
    }

    public function test_telephone_field_is_ignored_for_non_super_admin(): void
    {
        $user = User::factory()->create();
        $originalTelephone = $user->telephoneIdentity()?->value;

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'prenom' => 'Test',
                'nom' => 'User',
                'email' => $user->email,
                'telephone' => '+224699999999',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $this->assertSame($originalTelephone, $user->fresh()->telephoneIdentity()?->value);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
