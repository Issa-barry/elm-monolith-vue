<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Client;
use App\Models\Livreur;
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

    public function test_me_exposes_civil_name_as_nom_affichage(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->personne->update(['prenom' => 'Mamadou', 'nom' => 'DIALLO']);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('nom_affichage', 'Mamadou DIALLO');
    }

    public function test_me_falls_back_on_livreur_name_when_civil_identity_is_empty(): void
    {
        // Cas réel 02/11 : livreur connu par son seul nom_complet, l'espace client affichait
        // "null null" faute de nom exploitable dans /me.
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->personne->update(['prenom' => null, 'nom' => null, 'nom_complet' => null]);
        $livreur = Livreur::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom_complet' => 'Camara Ya Moussa',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('prenom', null)
            ->assertJsonPath('nom', null)
            ->assertJsonPath('nom_affichage', 'Camara Ya Moussa')
            ->assertJsonPath('context.livreur_id', $livreur->id);
    }

    public function test_me_prefers_civil_name_over_provisional_livreur_designation(): void
    {
        // Règle du 02/11 : créé par le staff avec son seul numéro ("Chauffeur-1"), le livreur
        // saisit son vrai nom à l'inscription.
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->personne->update(['prenom' => 'Mamadou', 'nom' => 'DIALLO', 'nom_complet' => 'Chauffeur-1']);
        $livreur = Livreur::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom_complet' => 'Chauffeur-1',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('nom_affichage', 'Mamadou DIALLO');

        $this->assertSame('Chauffeur-1', $livreur->fresh()->nom_complet);
        $this->assertSame('Chauffeur-1', $livreur->fresh()->libelleAffichage());
    }

    public function test_me_keeps_livreur_designation_when_civil_name_is_incomplete(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->personne->update(['prenom' => 'Mamadou', 'nom' => null, 'nom_complet' => null]);
        Livreur::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom_complet' => 'Chauffeur-1',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('nom_affichage', 'Chauffeur-1');
    }

    public function test_me_shows_partial_name_when_no_full_name_is_known(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->personne->update(['prenom' => null, 'nom' => 'DIALLO', 'nom_complet' => null]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('nom_affichage', 'DIALLO');
    }

    public function test_me_falls_back_on_client_name_when_civil_identity_is_empty(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->personne->update(['prenom' => null, 'nom' => null, 'nom_complet' => null]);
        Client::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'prenom' => null,
            'nom' => null,
            'nom_complet' => 'Boutique Sylla',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('nom_affichage', 'Boutique Sylla');
    }

    public function test_me_returns_null_nom_affichage_without_any_known_name(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->personne->update(['prenom' => null, 'nom' => null, 'nom_complet' => null, 'surnom' => null]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('nom_affichage', null);
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
