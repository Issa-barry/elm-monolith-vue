<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class RegisterLookupTest extends TestCase
{
    use RefreshDatabase;

    // ─── Accès ────────────────────────────────────────────────────────────────

    public function test_lookup_is_accessible_to_guests(): void
    {
        $this->postJson(route('register.lookup'), ['telephone' => '+224620000001'])
            ->assertStatus(200);
    }

    public function test_lookup_requires_telephone(): void
    {
        $this->postJson(route('register.lookup'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_lookup_rejects_invalid_phone(): void
    {
        $this->postJson(route('register.lookup'), ['telephone' => 'pas-un-numero'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Numéro de téléphone invalide.']);
    }

    // ─── Statut user_exists ───────────────────────────────────────────────────

    public function test_lookup_returns_user_exists_when_phone_in_users(): void
    {
        User::factory()->create(['telephone' => '+224620000002']);

        $this->postJson(route('register.lookup'), ['telephone' => '+224620000002'])
            ->assertOk()
            ->assertJson(['status' => 'user_exists']);
    }

    // ─── Statut prefill_available ─────────────────────────────────────────────

    public function test_lookup_returns_prefill_from_client_without_user(): void
    {
        $org = Organization::factory()->create();
        Client::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000003',
            'user_id' => null,
            'prenom' => 'Mamadou',
            'nom' => 'DIALLO',
        ]);

        $response = $this->postJson(route('register.lookup'), ['telephone' => '+224620000003']);

        $response->assertOk()
            ->assertJson([
                'status' => 'prefill_available',
                'prefill' => ['prenom' => 'Mamadou', 'nom' => 'DIALLO'],
            ]);
    }

    public function test_lookup_returns_prefill_from_livreur(): void
    {
        $org = Organization::factory()->create();
        Livreur::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000004',
            'prenom' => 'Ibrahima',
            'nom' => 'CAMARA',
        ]);

        $response = $this->postJson(route('register.lookup'), ['telephone' => '+224620000004']);

        $response->assertOk()
            ->assertJson([
                'status' => 'prefill_available',
                'prefill' => ['prenom' => 'Ibrahima', 'nom' => 'CAMARA'],
            ]);
    }

    public function test_lookup_returns_prefill_from_proprietaire_without_user(): void
    {
        $org = Organization::factory()->create();
        Proprietaire::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000005',
            'user_id' => null,
            'prenom' => 'Alpha',
            'nom' => 'BARRY',
        ]);

        $response = $this->postJson(route('register.lookup'), ['telephone' => '+224620000005']);

        $response->assertOk()
            ->assertJson([
                'status' => 'prefill_available',
                'prefill' => ['prenom' => 'Alpha', 'nom' => 'BARRY'],
            ]);
    }

    public function test_lookup_ignores_client_that_already_has_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['telephone' => null]);
        Client::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000006',
            'user_id' => $user->id,
        ]);

        $response = $this->postJson(route('register.lookup'), ['telephone' => '+224620000006']);

        $response->assertOk()
            ->assertJson(['status' => 'not_found']);
    }

    // ─── Statut not_found ─────────────────────────────────────────────────────

    public function test_lookup_returns_not_found_for_unknown_phone(): void
    {
        $this->postJson(route('register.lookup'), ['telephone' => '+224699999999'])
            ->assertOk()
            ->assertJson(['status' => 'not_found', 'prefill' => null]);
    }

    // ─── Génération OTP en session ────────────────────────────────────────────

    public function test_lookup_stores_otp_in_session_when_not_user_exists(): void
    {
        $this->postJson(route('register.lookup'), ['telephone' => '+224620000007']);

        $this->assertTrue(
            Session::has('register_otp.+224620000007'),
            'OTP should be stored in session after lookup.',
        );
    }

    public function test_lookup_does_not_store_otp_when_user_exists(): void
    {
        User::factory()->create(['telephone' => '+224620000008']);

        $this->postJson(route('register.lookup'), ['telephone' => '+224620000008']);

        $this->assertFalse(
            Session::has('register_otp.+224620000008'),
            'OTP should NOT be stored when phone already in users.',
        );
    }

    // ─── Téléphone normalisé ──────────────────────────────────────────────────

    public function test_lookup_normalizes_phone_with_spaces(): void
    {
        User::factory()->create(['telephone' => '+224620000009']);

        $this->postJson(route('register.lookup'), ['telephone' => '+224 620 000 009'])
            ->assertOk()
            ->assertJson(['status' => 'user_exists']);
    }
}
