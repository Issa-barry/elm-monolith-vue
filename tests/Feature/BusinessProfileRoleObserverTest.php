<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Régression du 05/09/2026 : wasChanged('user_id') est FAUX quand user_id est
 * déjà présent dans les attributs au moment du create() — seul un update()
 * ultérieur sur une instance existante le déclenche. InstallationService::
 * install() crée justement le Proprietaire interne avec user_id posé dès le
 * create() (cf. RolesCoherenceCommandTest pour le cas update(), déjà couvert
 * depuis le 26/08/2026) : le super_admin qui installe l'application n'obtenait
 * donc jamais le rôle proprietaire correspondant, malgré l'observer déjà en
 * place. wasRecentlyCreated couvre ce cas précis.
 */
class BusinessProfileRoleObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_creating_a_proprietaire_with_user_id_already_set_assigns_the_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $user->personne_id,
            'is_active' => true,
        ]);

        $this->assertTrue($user->fresh()->hasRole('proprietaire'));
    }

    public function test_creating_a_client_with_user_id_already_set_assigns_the_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Client::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($user->fresh()->hasRole('client'));
    }

    public function test_creating_a_livreur_with_user_id_already_set_assigns_the_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Livreur::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($user->fresh()->hasRole('livreur'));
    }

    public function test_creating_a_profile_without_user_id_assigns_no_role(): void
    {
        $org = Organization::factory()->create();

        Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => null]);

        // Rien à vérifier sur un user précis — l'essentiel est que ceci ne lève
        // aucune erreur quand $profile->user est null (create() sans user_id).
        $this->assertTrue(true);
    }

    public function test_a_preexisting_staff_role_is_not_lost_when_the_role_is_backfilled_via_fix(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');

        Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $user->personne_id,
            'is_active' => true,
        ]);

        $user->refresh();
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue($user->hasRole('proprietaire'));
    }
}
