<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_without_known_role_is_logged_out_instead_of_looping(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        // Ne doit jamais rediriger vers elle-même ('/') : ça boucle indéfiniment
        // depuis que cette route ne rend plus de page marketing.
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
