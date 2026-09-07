<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageLogStatus;
use App\Enums\OtpPurpose;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Écran de monitoring Communications (cf. rapport monitoring, 07/09/2026) —
 * permission dédiée `communications.read`, jamais de bypass par nom de rôle,
 * isolation organisationnelle.
 */
class CommunicationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Organization $org, array $permissions): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeLog(Organization $org, array $overrides = []): MessageLog
    {
        return MessageLog::create(array_merge([
            'organization_id' => $org->id,
            'channel' => MessageChannel::SMS,
            'direction' => MessageDirection::OUTBOUND,
            'purpose' => OtpPurpose::LOGIN,
            'provider' => 'nimba',
            'masked_recipient' => '+224 ••• •• 12',
            'status' => MessageLogStatus::SENT,
        ], $overrides));
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, []);

        $this->actingAs($user)->get('/backoffice/communications')->assertStatus(403);
    }

    public function test_a_user_with_the_permission_can_see_the_index(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.read']);
        $this->makeLog($org);

        $response = $this->actingAs($user)->get('/backoffice/communications');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Communications/Index')
            ->has('logs.data', 1));
    }

    public function test_a_message_log_from_another_organization_is_never_visible(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUser($orgA, ['communications.read']);

        $this->makeLog($orgA);
        $this->makeLog($orgB);

        $response = $this->actingAs($user)->get('/backoffice/communications');

        $response->assertInertia(fn ($page) => $page
            ->component('Communications/Index')
            ->has('logs.data', 1));
    }

    public function test_the_status_filter_scopes_the_results(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.read']);

        $this->makeLog($org, ['status' => MessageLogStatus::SENT]);
        $this->makeLog($org, ['status' => MessageLogStatus::FAILED]);

        $response = $this->actingAs($user)->get('/backoffice/communications?status=failed');

        $response->assertInertia(fn ($page) => $page
            ->component('Communications/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.status', 'failed'));
    }

    // ── Audit sécurité ciblé avant commit (07/09/2026) ──────────────────────────

    /**
     * `organization_id` est nullable sur `users` (cascade `nullOnDelete` si
     * l'organisation d'un compte est supprimée) — un `where('organization_id',
     * $orgId)` avec `$orgId === null` serait automatiquement réécrit par
     * Eloquent en `whereNull('organization_id')` (comportement natif du query
     * builder dès que la valeur passée à `where()` est `null`), ce qui
     * afficherait TOUTES les lignes `message_logs` sans organisation à ce
     * compte — jamais les communications d'une AUTRE organisation identifiée,
     * mais un vrai croisement de données non voulu. `CommunicationController`
     * doit refuser explicitement plutôt que laisser ce comportement implicite
     * s'appliquer.
     */
    public function test_a_user_whose_own_organization_id_is_null_sees_no_message_logs(): void
    {
        $org = Organization::factory()->create();
        // super_admin : le seul rôle réellement exempté de RequireSiteAssigned
        // (cf. sa docblock) — c'est aussi le cas plausible où `organization_id`
        // pourrait être null en pratique (colonne nullable via nullOnDelete si
        // l'organisation d'un compte est supprimée). syncPermissions(Permission::all())
        // lui donne `communications.read` sans avoir à le donner à la main.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'communications.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole('super_admin');
        $user->givePermissionTo(['communications.read']);

        // Log rattaché à une organisation réelle ET log orphelin (organization_id
        // null, ex: OTP de vérification téléphone avant création de compte) —
        // ni l'un ni l'autre ne doit être visible par ce compte sans organisation.
        $this->makeLog($org);
        $this->makeLog($org, ['organization_id' => null]);

        $this->actingAs($user)->get('/backoffice/communications')->assertStatus(403);
    }

    /**
     * Un log `organization_id = null` (OTP de vérification téléphone avant
     * création de compte, cf. MessageLogService::logSmsOtpAttempt()) ne doit
     * jamais apparaître sur l'écran d'une organisation réelle — c'est le
     * complément du test précédent : ici c'est la DONNÉE qui est orpheline,
     * pas le compte qui consulte.
     */
    public function test_an_organization_less_message_log_is_never_visible_from_a_real_organizations_screen(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, ['communications.read']);

        $this->makeLog($org);
        $this->makeLog($org, ['organization_id' => null]);

        $response = $this->actingAs($user)->get('/backoffice/communications');

        $response->assertInertia(fn ($page) => $page
            ->component('Communications/Index')
            ->has('logs.data', 1));
    }

    /**
     * `admin_entreprise` et `manager` obtiennent `communications.read` par
     * défaut via RolesAndPermissionsSeeder (cf. rapport) — vérifié ici contre
     * le VRAI seeder plutôt qu'une permission attribuée à la main, pour
     * prouver que le rôle réellement assigné en production voit l'écran.
     */
    public function test_admin_entreprise_and_manager_see_only_their_own_organization_via_the_real_seeder(): void
    {
        RolesAndPermissionsSeeder::seedRolesEtPermissions();

        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $site = Site::create(['organization_id' => $orgA->id, 'nom' => 'Siège', 'type' => 'depot']);

        $admin = User::factory()->create(['organization_id' => $orgA->id]);
        $admin->assignRole('admin_entreprise');
        $admin->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $manager = User::factory()->create(['organization_id' => $orgA->id]);
        $manager->assignRole('manager');
        $manager->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->makeLog($orgA);
        $this->makeLog($orgB);

        foreach ([$admin, $manager] as $user) {
            $response = $this->actingAs($user)->get('/backoffice/communications');

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->component('Communications/Index')
                ->has('logs.data', 1));
        }
    }

    /**
     * `commerciale`/`comptable` n'ont volontairement pas `communications.read`
     * dans RolesAndPermissionsSeeder (périmètre technique/administratif, pas
     * commercial/comptable) — vérifié contre le vrai seeder.
     */
    public function test_commerciale_role_has_no_access_via_the_real_seeder(): void
    {
        RolesAndPermissionsSeeder::seedRolesEtPermissions();

        $org = Organization::factory()->create();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('commerciale');
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->get('/backoffice/communications')->assertStatus(403);
    }
}
