<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_identity_and_context_for_valid_token(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonStructure([
                'id', 'prenom', 'nom', 'telephone', 'email', 'roles', 'is_active', 'qr_payload',
                'context' => ['organization_id', 'client_id', 'proprietaire_id', 'livreur_id'],
            ])
            ->assertJsonPath('context.organization_id', $org->id);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson(route('api.auth.me'))
            ->assertStatus(401)
            ->assertJson(['message' => 'Non authentifié.']);
    }

    public function test_me_rejects_disabled_account_even_with_a_valid_token(): void
    {
        $user = User::factory()->create(['is_active' => false, 'status' => User::STATUS_INACTIVE]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_blocked');
    }

    public function test_me_rejects_revoked_token(): void
    {
        User::factory()->create(['telephone' => '+224620000100', 'password' => 'Password@123']);

        $token = $this->postJson(route('api.auth.login'), [
            'telephone' => '+224620000100',
            'password' => 'Password@123',
            'device_name' => 'test',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.auth.logout'))
            ->assertOk();

        // Le guard 'sanctum' cache l'utilisateur résolu pour la durée du test (même
        // mécanisme que le problème connu sous Octane) : sans ce reset, cet appel
        // verrait encore l'utilisateur authentifié par l'appel précédent, alors même
        // que son token vient d'être supprimé en base.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.auth.me'))
            ->assertStatus(401);
    }
}
