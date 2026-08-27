<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Couvre la matrice cible décidée le 26/08/2026 : un compte staff (rôle système ou
 * personnalisé d'organisation) qui cumule un rôle client/proprietaire/livreur garde
 * l'accès aux DEUX espaces avec un seul compte — ni deux comptes séparés, ni
 * exclusion mutuelle. Un compte n'ayant QUE des rôles externes reste refusé au
 * backoffice ; un compte n'ayant QUE des rôles staff reste refusé à l'espace client.
 *
 * | Profil                   | Backoffice | Espace client |
 * |--------------------------|-----------:|--------------:|
 * | Staff uniquement         |        OUI |            NON |
 * | Client uniquement        |        NON |            OUI |
 * | Proprietaire uniquement  |        NON |            OUI |
 * | Livreur uniquement       |        NON |            OUI |
 * | Staff + Client           |        OUI |            OUI |
 * | Staff + Proprietaire     |        OUI |            OUI |
 * | Staff + Livreur          |        OUI |            OUI |
 */
class RoleCumulAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  string[]  $roleNames */
    private function userWithRoles(array $roleNames): User
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        foreach ($roleNames as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user->assignRole($roleName);
        }

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Site Test', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function backoffice(User $user): TestResponse
    {
        return $this->actingAs($user)->get('/backoffice/dashboard');
    }

    private function espaceClient(User $user): TestResponse
    {
        return $this->actingAs($user)->get('/client/dashboard');
    }

    public function test_staff_uniquement_backoffice_oui_espace_client_non(): void
    {
        $user = $this->userWithRoles(['admin_entreprise']);

        $this->backoffice($user)->assertOk();
        $this->espaceClient($user)->assertForbidden();
    }

    public function test_client_uniquement_backoffice_non_espace_client_oui(): void
    {
        $user = $this->userWithRoles(['client']);

        $this->backoffice($user)->assertForbidden();
        $this->espaceClient($user)->assertOk();
    }

    public function test_proprietaire_uniquement_backoffice_non_espace_client_oui(): void
    {
        $user = $this->userWithRoles(['proprietaire']);

        $this->backoffice($user)->assertForbidden();
        $this->espaceClient($user)->assertOk();
    }

    public function test_livreur_uniquement_backoffice_non_espace_client_oui(): void
    {
        $user = $this->userWithRoles(['livreur']);

        $this->backoffice($user)->assertForbidden();
        $this->espaceClient($user)->assertOk();
    }

    public function test_staff_plus_client_backoffice_oui_espace_client_oui(): void
    {
        $user = $this->userWithRoles(['manager', 'client']);

        $this->backoffice($user)->assertOk();
        $this->espaceClient($user)->assertOk();
    }

    public function test_staff_plus_proprietaire_backoffice_oui_espace_client_oui(): void
    {
        $user = $this->userWithRoles(['admin_entreprise', 'proprietaire']);

        $this->backoffice($user)->assertOk();
        $this->espaceClient($user)->assertOk();
    }

    public function test_staff_plus_livreur_backoffice_oui_espace_client_oui(): void
    {
        $user = $this->userWithRoles(['comptable', 'livreur']);

        $this->backoffice($user)->assertOk();
        $this->espaceClient($user)->assertOk();
    }

    /** Un compte sans aucun rôle ne doit jamais entrer dans le backoffice ni l'espace client. */
    public function test_aucun_role_backoffice_non_espace_client_non(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->backoffice($user)->assertForbidden();
        $this->espaceClient($user)->assertForbidden();
    }

    // ── Redirection post-connexion (AuthRedirects) ──────────────────────────────

    public function test_redirection_par_defaut_staff_plus_proprietaire_va_au_backoffice(): void
    {
        $user = $this->userWithRoles(['admin_entreprise', 'proprietaire']);

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_redirection_par_defaut_proprietaire_seul_va_a_lespace_client(): void
    {
        $user = $this->userWithRoles(['proprietaire']);

        $this->actingAs($user)->get('/')->assertRedirect(route('client.dashboard'));
    }
}
