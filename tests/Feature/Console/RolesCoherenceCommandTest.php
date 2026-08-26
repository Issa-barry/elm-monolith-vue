<?php

namespace Tests\Feature\Console;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RolesCoherenceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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
        Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        $this->artisan('roles:verifier-coherence-metier')
            ->expectsOutputToContain('1 écart(s) détecté')
            ->assertExitCode(1);
    }

    public function test_detects_a_livreur_linked_without_the_matching_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Livreur::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

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
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

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
        Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        $this->artisan('roles:verifier-coherence-metier', ['--fix' => true])->assertExitCode(0);

        $this->artisan('roles:verifier-coherence-metier')
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);
    }

    public function test_without_fix_does_not_modify_any_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Proprietaire::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        $this->artisan('roles:verifier-coherence-metier')->assertExitCode(1);

        $this->assertFalse($user->fresh()->hasRole('proprietaire'));
    }

    public function test_organization_option_scopes_the_check(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        Proprietaire::factory()->create(['organization_id' => $orgA->id, 'user_id' => $userA->id]);

        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
        $userB->assignRole('proprietaire');
        Proprietaire::factory()->create(['organization_id' => $orgB->id, 'user_id' => $userB->id]);

        $this->artisan('roles:verifier-coherence-metier', ['--organization' => $orgB->id])
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);
    }
}
