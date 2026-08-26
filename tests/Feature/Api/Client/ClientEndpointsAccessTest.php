<?php

namespace Tests\Feature\Api\Client;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Couvre la section 13 (autorisation explicite) et la section 15 (fallback
 * téléphone inter-organisation) de l'audit backend du 26/08/2026, sur les
 * endpoints Client\* (vehicules/mine, gains/mine, vehicules/{id}/frais).
 */
class ClientEndpointsAccessTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    public function test_role_gate_blocks_a_user_without_client_proprietaire_or_livreur_role(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $staff = User::factory()->create(['organization_id' => $org->id]);
        $staff->assignRole('super_admin');

        Sanctum::actingAs($staff, ['*']);

        $this->getJson(route('client.vehicules.mine'))->assertStatus(403);
        $this->getJson(route('client.gains.mine'))->assertStatus(403);
    }

    public function test_proprietaire_sees_only_vehicules_from_their_own_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $proprietaireA = $this->makeProprietaireUser($orgA);
        $vehiculeA = Vehicule::factory()->create([
            'organization_id' => $orgA->id,
            'proprietaire_id' => $proprietaireA->proprietaire->id,
        ]);

        $proprietaireB = $this->makeProprietaireUser($orgB);
        Vehicule::factory()->create([
            'organization_id' => $orgB->id,
            'proprietaire_id' => $proprietaireB->proprietaire->id,
        ]);

        Sanctum::actingAs($proprietaireA, ['*']);

        $response = $this->getJson(route('client.vehicules.mine'))->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertSame([$vehiculeA->id], $ids);
    }

    public function test_vehicules_mine_exposes_the_assigned_chauffeur_name(): void
    {
        $org = Organization::factory()->create();
        $proprietaire = $this->makeProprietaireUser($org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->proprietaire->id,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $org->id, 'nom_complet' => 'Issa M.']);
        $convoyeur = Livreur::factory()->create(['organization_id' => $org->id, 'nom_complet' => 'Amara K.']);
        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $convoyeur->id, 'role' => 'convoyeur', 'ordre' => 1]);

        Sanctum::actingAs($proprietaire, ['*']);

        $this->getJson(route('client.vehicules.mine'))
            ->assertOk()
            ->assertJsonFragment(['id' => $vehicule->id, 'conducteur' => 'Issa M.']);
    }

    public function test_vehicules_mine_conducteur_is_null_without_a_chauffeur(): void
    {
        $org = Organization::factory()->create();
        $proprietaire = $this->makeProprietaireUser($org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->proprietaire->id,
        ]);

        Sanctum::actingAs($proprietaire, ['*']);

        $this->getJson(route('client.vehicules.mine'))
            ->assertOk()
            ->assertJsonFragment(['id' => $vehicule->id, 'conducteur' => null]);
    }

    /**
     * Reproduit précisément la faille corrigée dans ClientIdentityResolver : un
     * profil Proprietaire déjà réclamé par un AUTRE compte ne doit jamais être
     * apparié pour un second utilisateur simplement parce que la chaîne de
     * téléphone SAISIE SUR LA FICHE proprietaire (personne.telephone, un champ
     * libre indépendant de l'identifiant de connexion) coïncide avec le numéro de
     * connexion de ce second utilisateur — quand celui-ci n'a pas encore
     * d'organization_id connu. Les deux téléphones (compte de connexion de
     * proprietaireA vs fiche métier de proprietaireA) sont volontairement
     * différents ici : `user_auth_identities` impose une unicité globale du
     * téléphone de CONNEXION, donc deux comptes ne peuvent jamais partager le même
     * téléphone de connexion — mais rien n'empêche la fiche métier (texte libre)
     * de coïncider avec le téléphone de connexion d'un compte totalement différent.
     */
    public function test_telephone_collision_does_not_leak_another_organizations_proprietaire(): void
    {
        $orgA = Organization::factory()->create();
        $collisionTelephone = '+224620009999';

        // Proprietaire de l'organisation A, légitimement réclamé par un compte dont
        // le téléphone de CONNEXION n'a rien à voir avec la collision testée.
        $proprietaireA = $this->makeProprietaireUser(
            $orgA,
            ['telephone' => '+224600000001'],
            ['telephone' => $collisionTelephone],
        );
        Vehicule::factory()->create([
            'organization_id' => $orgA->id,
            'proprietaire_id' => $proprietaireA->proprietaire->id,
        ]);

        // Un second utilisateur, sans organisation connue, dont le téléphone de
        // CONNEXION coïncide avec la fiche métier (texte libre) de proprietaireA —
        // n'a lui-même aucun profil métier nulle part.
        $this->ensureClientRoles();
        $userB = User::factory()->create([
            'organization_id' => null,
            'telephone' => $collisionTelephone,
        ]);
        $userB->assignRole('proprietaire');

        Sanctum::actingAs($userB, ['*']);

        $this->getJson(route('client.vehicules.mine'))
            ->assertOk()
            ->assertJson([]);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('context.proprietaire_id', null)
            ->assertJsonPath('context.organization_id', null);
    }

    public function test_vehicule_frais_endpoint_rejects_a_vehicule_not_owned_by_the_caller(): void
    {
        $org = Organization::factory()->create();

        $proprietaireA = $this->makeProprietaireUser($org);
        $proprietaireB = $this->makeProprietaireUser($org);

        $vehiculeB = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireB->proprietaire->id,
        ]);

        Sanctum::actingAs($proprietaireA, ['*']);

        // Avant correctif : seule l'organisation était vérifiée, donc accessible.
        $this->getJson(route('client.vehicules.frais', ['vehiculeId' => $vehiculeB->id]))
            ->assertStatus(404);
    }

    public function test_vehicule_frais_endpoint_allows_the_owning_proprietaire(): void
    {
        $org = Organization::factory()->create();
        $proprietaire = $this->makeProprietaireUser($org);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->proprietaire->id,
        ]);

        Sanctum::actingAs($proprietaire, ['*']);

        $this->getJson(route('client.vehicules.frais', ['vehiculeId' => $vehicule->id]))
            ->assertOk()
            ->assertJson([]);
    }
}
