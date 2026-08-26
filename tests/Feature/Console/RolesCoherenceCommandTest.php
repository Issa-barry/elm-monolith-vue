<?php

namespace Tests\Feature\Console;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Depuis l'introduction de BusinessProfileRoleObserver (26/08/2026), poser
 * user_id via Eloquent (create()/update() sur une instance) attribue TOUJOURS le
 * rôle correspondant — il n'est donc plus possible de reproduire un écart via le
 * chemin normal de l'application. Les tests ci-dessous simulent volontairement
 * une divergence par un update() SQL brut (DB::table(...)), qui contourne les
 * events Eloquent : exactement le genre d'intervention manuelle/migration de
 * données que cette commande doit encore pouvoir détecter et rattraper malgré
 * l'observer.
 */
class RolesCoherenceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Simule un rattachement qui a contourné Eloquent (donc l'observer) — ex: migration de données, correctif SQL manuel. */
    private function linkBypassingObserver(string $table, string $id, string $userId): void
    {
        DB::table($table)->where('id', $id)->update(['user_id' => $userId]);
    }

    public function test_eloquent_update_keeps_role_in_sync_via_the_observer(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => null]);

        $proprietaire->update(['user_id' => $user->id]);

        $this->assertTrue($user->fresh()->hasRole('proprietaire'));

        $this->artisan('roles:verifier-coherence-metier')
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);
    }

    public function test_reports_no_gap_when_everything_is_in_sync(): void
    {
        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('proprietaire');
        Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        $this->artisan('roles:verifier-coherence-metier')
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);
    }

    public function test_detects_a_proprietaire_linked_without_the_matching_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => null]);
        $this->linkBypassingObserver('proprietaires', $proprietaire->id, $user->id);

        $this->artisan('roles:verifier-coherence-metier')
            ->expectsOutputToContain('1 écart(s) détecté')
            ->assertExitCode(1);
    }

    public function test_detects_a_livreur_linked_without_the_matching_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id, 'user_id' => null]);
        $this->linkBypassingObserver('livreurs', $livreur->id, $user->id);

        $this->artisan('roles:verifier-coherence-metier')
            ->expectsOutputToContain('1 écart(s) détecté')
            ->assertExitCode(1);
    }

    public function test_fix_assigns_the_missing_role_without_touching_other_roles(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => null]);
        $this->linkBypassingObserver('proprietaires', $proprietaire->id, $user->id);

        $this->artisan('roles:verifier-coherence-metier', ['--fix' => true])
            ->expectsOutputToContain('1 écart(s) corrigé')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertTrue($user->hasRole('proprietaire'));
        $this->assertTrue($user->hasRole('super_admin'), 'le rôle staff existant ne doit pas être perdu');
        // Le rattachement métier lui-même n'est jamais modifié par --fix.
        $this->assertSame($user->id, $proprietaire->fresh()->user_id);
    }

    public function test_fix_leaves_no_gap_on_a_second_run(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => null]);
        $this->linkBypassingObserver('proprietaires', $proprietaire->id, $user->id);

        $this->artisan('roles:verifier-coherence-metier', ['--fix' => true])->assertExitCode(0);

        $this->artisan('roles:verifier-coherence-metier')
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);
    }

    public function test_without_fix_does_not_modify_any_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => null]);
        $this->linkBypassingObserver('proprietaires', $proprietaire->id, $user->id);

        $this->artisan('roles:verifier-coherence-metier')->assertExitCode(1);

        $this->assertFalse($user->fresh()->hasRole('proprietaire'));
    }

    public function test_organization_option_scopes_the_check(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $proprietaireA = Proprietaire::factory()->create(['organization_id' => $orgA->id, 'user_id' => null]);
        $this->linkBypassingObserver('proprietaires', $proprietaireA->id, $userA->id);

        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
        $userB->assignRole('proprietaire');
        Proprietaire::factory()->create(['organization_id' => $orgB->id, 'user_id' => $userB->id]);

        $this->artisan('roles:verifier-coherence-metier', ['--organization' => $orgB->id])
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);
    }
}
