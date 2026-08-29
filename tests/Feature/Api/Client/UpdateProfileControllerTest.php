<?php

namespace Tests\Feature\Api\Client;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class UpdateProfileControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    public function test_proprietaire_can_update_their_localisation(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, [], ['ville' => 'Conakry', 'adresse' => null]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), [
            'pays' => 'Guinée',
            'code_pays' => 'GN',
            'ville' => 'Kindia',
            'adresse' => 'Quartier Manquépas',
        ])
            ->assertOk()
            ->assertJsonPath('profile.localisation.ville', 'Kindia')
            ->assertJsonPath('profile.localisation.adresse', 'Quartier Manquépas');

        $this->assertSame('Kindia', $user->fresh()->proprietaire->personne->ville);
    }

    public function test_update_does_not_touch_identity_or_contact_fields(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, [], ['nom' => 'SIDIBÉ', 'telephone' => '+224620000200']);

        Sanctum::actingAs($user, ['*']);

        // 'nom'/'telephone' ne font pas partie des règles de validation : ils sont
        // silencieusement ignorés (FormRequest n'accepte que pays/code_pays/ville/adresse).
        $this->patchJson(route('client.profile.update'), [
            'nom' => 'AUTRE NOM',
            'telephone' => '+224699999999',
            'ville' => 'Labé',
        ])->assertOk();

        $proprietaire = $user->fresh()->proprietaire->fresh();
        $this->assertSame('SIDIBÉ', $proprietaire->personne->nom);
        $this->assertSame('+224620000200', $proprietaire->personne->telephone);
        $this->assertSame('Labé', $proprietaire->personne->ville);
    }

    public function test_client_can_update_their_localisation_directly(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');
        $client = Client::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['ville' => 'Mamou'])
            ->assertOk()
            ->assertJsonPath('profile.localisation.ville', 'Mamou');

        $this->assertSame('Mamou', $client->fresh()->ville);
    }

    public function test_returns_404_when_no_profile_is_linked(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['ville' => 'Kankan'])
            ->assertStatus(404);
    }

    public function test_requires_a_client_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['ville' => 'Kankan'])
            ->assertStatus(403);
    }
}
