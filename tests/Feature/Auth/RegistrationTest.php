<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Affichage ────────────────────────────────────────────────────────────

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get(route('register'))->assertStatus(200);
    }

    public function test_authenticated_user_cannot_access_register(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('register'))
            ->assertRedirect(route('dashboard'));
    }

    // ─── Inscription réussie ───────────────────────────────────────────────────

    public function test_registration_without_phone(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'password' => 'Password@123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email'     => 'test@example.com',
            'telephone' => null,
        ]);

        $user = \App\Models\User::where('email', 'test@example.com')->first();
        $this->assertTrue($user->hasRole('client'));
    }

    public function test_registration_with_valid_guinee_phone(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Mamadou',
            'nom'    => 'Diallo',
            'email'                 => 'mamadou@example.com',
            'telephone'             => '+224620000000',
            'telephone_country'     => 'GN',
            'telephone_local'       => '620000000',
            'password' => 'Password@123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'email'     => 'mamadou@example.com',
            'telephone' => '+224620000000',
        ]);
    }

    public function test_registration_with_valid_senegal_phone(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Fatou',
            'nom'    => 'Diop',
            'email'                 => 'fatou@example.com',
            'telephone'             => '+221701234567',
            'telephone_country'     => 'SN',
            'telephone_local'       => '701234567',
            'password' => 'Password@123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'telephone' => '+221701234567',
        ]);
    }

    public function test_registration_with_valid_france_phone(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Jean',
            'nom'    => 'Dupont',
            'email'                 => 'jean@example.com',
            'telephone'             => '+33612345678',
            'telephone_country'     => 'FR',
            'telephone_local'       => '612345678',
            'password' => 'Password@123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'telephone' => '+33612345678',
        ]);
    }

    // ─── Validation – champs obligatoires ─────────────────────────────────────

    public function test_registration_fails_without_prenom(): void
    {
        $this->post(route('register.store'), [
            'prenom' => '',
            'nom'    => 'User',
            'email'  => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('prenom');

        $this->assertGuest();
    }

    public function test_registration_fails_with_prenom_too_short(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'A',
            'nom'    => 'User',
            'email'  => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('prenom');
    }

    public function test_registration_fails_with_nom_too_short(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'B',
            'email'  => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('nom');
    }

    public function test_registration_fails_without_nom(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => '',
            'email'  => 'test@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('nom');

        $this->assertGuest();
    }

    public function test_registration_succeeds_without_email(): void
    {
        $this->post(route('register.store'), [
            'prenom'   => 'Test',
            'nom'      => 'User',
            'password' => 'Password@123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['prenom' => 'Test', 'nom' => 'User', 'email' => null]);
    }

    public function test_registration_fails_with_invalid_email(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'not-an-email',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('email');
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'existing@example.com',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ─── Validation – mot de passe ────────────────────────────────────────────

    public function test_registration_fails_without_password(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'password'              => '',
            'password_confirmation' => '',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_fails_with_password_too_short(): void
    {
        $this->post(route('register.store'), [
            'prenom'   => 'Test',
            'nom'      => 'User',
            'email'    => 'test@example.com',
            'password' => 'Abc@123',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_fails_without_uppercase(): void
    {
        $this->post(route('register.store'), [
            'prenom'   => 'Test',
            'nom'      => 'User',
            'email'    => 'test@example.com',
            'password' => 'password@123',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_fails_without_symbol(): void
    {
        $this->post(route('register.store'), [
            'prenom'   => 'Test',
            'nom'      => 'User',
            'email'    => 'test@example.com',
            'password' => 'Password123',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_succeeds_without_password_confirmation(): void
    {
        $this->post(route('register.store'), [
            'prenom'   => 'Test',
            'nom'      => 'User',
            'email'    => 'test@example.com',
            'password' => 'Password@123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    // ─── Validation – téléphone ───────────────────────────────────────────────

    public function test_registration_fails_with_invalid_country_code(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'telephone_country'     => 'XX',
            'telephone_local'       => '123456789',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone_country');
    }

    public function test_registration_fails_with_phone_too_short_for_country(): void
    {
        // Guinée attend 9 chiffres
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'telephone_country'     => 'GN',
            'telephone_local'       => '12345',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone');
    }

    public function test_registration_fails_with_phone_too_long_for_country(): void
    {
        // Guinée-Bissau attend 7 chiffres
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'telephone_country'     => 'GW',
            'telephone_local'       => '12345678901',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone');
    }

    public function test_registration_fails_with_non_numeric_phone(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'telephone_country'     => 'GN',
            'telephone_local'       => 'abc123def',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('telephone_local');
    }

    // ─── Intégrité BDD ────────────────────────────────────────────────────────

    public function test_password_is_hashed_in_database(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'password' => 'Password@123',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('password', $user->password);
    }

    public function test_user_is_logged_in_after_registration(): void
    {
        $this->post(route('register.store'), [
            'prenom' => 'Test',
            'nom'    => 'User',
            'email'                 => 'test@example.com',
            'password' => 'Password@123',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
    }
}
