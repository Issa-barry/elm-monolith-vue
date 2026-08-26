<?php

namespace Tests\Feature\Api\Client;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    public function test_returns_the_full_sheet_for_a_personne_physique_proprietaire(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, [], [
            'nom' => 'SIDIBÉ',
            'prenom' => 'Moussa',
            'pays' => 'Guinée',
            'code_pays' => 'GN',
            'ville' => 'Conakry',
            'adresse' => 'Matoto, Carrefour',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile.type', 'proprietaire')
            ->assertJsonPath('profile.entreprise', null)
            ->assertJsonPath('profile.identite.nom', 'SIDIBÉ')
            ->assertJsonPath('profile.identite.prenom', 'Moussa')
            ->assertJsonPath('profile.localisation.ville', 'Conakry')
            ->assertJsonPath('profile.localisation.pays', 'Guinée')
            ->assertJsonPath('profile.localisation.adresse', 'Matoto, Carrefour')
            ->assertJsonPath('profile.notifications.activite', true);
    }

    public function test_returns_raison_sociale_for_an_entreprise_proprietaire(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('proprietaire');
        Proprietaire::factory()->entreprise('Eau La Maman SARL')->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile.entreprise.raison_sociale', 'Eau La Maman SARL');
    }

    public function test_returns_the_sheet_for_a_client(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');
        Client::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'ville' => 'Kindia',
            'adresse' => 'Quartier Manquépas',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile.type', 'client')
            ->assertJsonPath('profile.localisation.ville', 'Kindia')
            ->assertJsonPath('profile.localisation.adresse', 'Quartier Manquépas');
    }

    public function test_returns_the_sheet_for_a_livreur(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, [], [
            'nom_complet' => 'Baba Ousou',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile.type', 'livreur')
            ->assertJsonPath('profile.identite.nom_affichage', 'Baba Ousou');
    }

    public function test_proprietaire_takes_priority_over_client_when_both_are_linked(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->ensureClientRoles();
        $user->assignRole('client');
        Client::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile.type', 'proprietaire');
    }

    public function test_returns_null_profile_when_the_role_is_present_but_no_business_record_is_linked(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))
            ->assertOk()
            ->assertJsonPath('profile', null);
    }

    public function test_requires_a_client_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');
        $user->removeRole('client');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.profile'))->assertStatus(403);
    }
}
