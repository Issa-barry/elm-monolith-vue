<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOrg(): Organization
    {
        return Organization::factory()->create();
    }

    private function makeSite(Organization $org): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    /**
     * Creates a staff user with sites.create + users.create permissions
     * and attaches a default site so RequireSiteAssigned is satisfied.
     */
    private function makeAdmin(Organization $org, Site $defaultSite): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);

        foreach (['sites.read', 'sites.create', 'users.create', 'users.read'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['sites.read', 'sites.create', 'users.create', 'users.read']);
        $user->sites()->attach($defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeInvitation(Site $site, array $overrides = []): UserInvitation
    {
        $token = \Illuminate\Support\Str::random(64);

        return UserInvitation::create(array_merge([
            'email' => 'invite@example.com',
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'role' => 'manager',
            'token_hash' => hash('sha256', $token),
            'invited_by' => User::factory()->create(['organization_id' => $site->organization_id])->id,
            'expires_at' => now()->addHours(24),
        ], $overrides));
    }

    /** Returns the plain token that maps to $invitation->token_hash. */
    private function plainToken(UserInvitation $invitation): string
    {
        // Regenerate a fresh token with known value so we can find it
        $token = \Illuminate\Support\Str::random(64);
        $invitation->update(['token_hash' => hash('sha256', $token)]);

        return $token;
    }

    // ── store (invite) ────────────────────────────────────────────────────────

    public function test_store_redirects_unauthenticated_user(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);

        $this->post(route('sites.invitations.store', $site), [
            'email' => 'x@x.com',
            'role' => 'manager',
        ])->assertRedirect(route('login'));
    }

    public function test_store_returns_403_without_users_create_permission(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sites.read', 'guard_name' => 'web']);
        // Note: no users.create permission given
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('sites.read');
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)
            ->post(route('sites.invitations.store', $site), [
                'email' => 'x@x.com',
                'role' => 'manager',
            ])->assertStatus(403);
    }

    public function test_store_returns_403_for_site_in_other_organization(): void
    {
        $org1 = $this->makeOrg();
        $org2 = $this->makeOrg();
        $ownSite = $this->makeSite($org1);
        $otherSite = $this->makeSite($org2);

        $admin = $this->makeAdmin($org1, $ownSite);

        $this->actingAs($admin)
            ->post(route('sites.invitations.store', $otherSite), [
                'email' => 'x@x.com',
                'role' => 'manager',
            ])->assertStatus(403);
    }

    public function test_store_creates_invitation_and_sends_mail(): void
    {
        Mail::fake();

        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $admin = $this->makeAdmin($org, $site);

        $this->actingAs($admin)
            ->post(route('sites.invitations.store', $site), [
                'email' => 'nouveau@example.com',
                'role' => 'manager',
            ])->assertRedirect();

        $this->assertDatabaseHas('user_invitations', [
            'email' => 'nouveau@example.com',
            'site_id' => $site->id,
            'role' => 'manager',
        ]);

        Mail::assertSentCount(1);
    }

    public function test_store_blocks_duplicate_pending_invitation(): void
    {
        Mail::fake();

        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $admin = $this->makeAdmin($org, $site);

        // First invite succeeds
        $this->actingAs($admin)
            ->post(route('sites.invitations.store', $site), [
                'email' => 'doublon@example.com',
                'role' => 'manager',
            ]);

        // Second invite for same email+site is blocked
        $this->actingAs($admin)
            ->post(route('sites.invitations.store', $site), [
                'email' => 'doublon@example.com',
                'role' => 'manager',
            ])->assertSessionHasErrors();

        $this->assertSame(1, UserInvitation::where('email', 'doublon@example.com')->count());
    }

    public function test_store_blocks_invitation_for_existing_user_email(): void
    {
        Mail::fake();

        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $admin = $this->makeAdmin($org, $site);

        User::factory()->create([
            'email' => 'existant@example.com',
            'organization_id' => $org->id,
        ]);

        $this->actingAs($admin)
            ->post(route('sites.invitations.store', $site), [
                'email' => 'existant@example.com',
                'role' => 'manager',
            ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('user_invitations', ['email' => 'existant@example.com']);
    }

    public function test_store_validates_required_fields(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $admin = $this->makeAdmin($org, $site);

        $this->actingAs($admin)
            ->post(route('sites.invitations.store', $site), [])
            ->assertSessionHasErrors(['email', 'role']);
    }

    // ── resend ────────────────────────────────────────────────────────────────

    public function test_resend_returns_403_for_user_from_other_org(): void
    {
        Mail::fake();

        $org1 = $this->makeOrg();
        $org2 = $this->makeOrg();
        $site1 = $this->makeSite($org1);
        $site2 = $this->makeSite($org2);

        $invitation = $this->makeInvitation($site2);
        $invitation->update(['expires_at' => now()->subHour()]); // expired

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.create', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org1->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('users.create');
        $user->sites()->attach($site1->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)
            ->post(route('invitations.resend', $invitation))
            ->assertStatus(403);
    }

    public function test_resend_regenerates_token_and_sends_mail(): void
    {
        Mail::fake();

        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $admin = $this->makeAdmin($org, $site);

        $invitation = $this->makeInvitation($site);
        $invitation->update(['expires_at' => now()->subHour()]); // expired
        $oldHash = $invitation->token_hash;

        $this->actingAs($admin)
            ->post(route('invitations.resend', $invitation))
            ->assertRedirect();

        $invitation->refresh();
        $this->assertNotEquals($oldHash, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        Mail::assertSentCount(1);
    }

    // ── destroy (revoke) ──────────────────────────────────────────────────────

    public function test_destroy_returns_403_for_user_from_other_org(): void
    {
        $org1 = $this->makeOrg();
        $org2 = $this->makeOrg();
        $site1 = $this->makeSite($org1);
        $site2 = $this->makeSite($org2);

        $invitation = $this->makeInvitation($site2);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'users.create', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org1->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('users.create');
        $user->sites()->attach($site1->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)
            ->delete(route('invitations.destroy', $invitation))
            ->assertStatus(403);
    }

    public function test_destroy_sets_revoked_at(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $admin = $this->makeAdmin($org, $site);

        $invitation = $this->makeInvitation($site);

        $this->actingAs($admin)
            ->delete(route('invitations.destroy', $invitation))
            ->assertRedirect();

        $invitation->refresh();
        $this->assertNotNull($invitation->revoked_at);
        $this->assertTrue($invitation->isRevoked());
    }

    // ── AcceptInvitationController::show ──────────────────────────────────────

    public function test_accept_show_returns_error_for_unknown_token(): void
    {
        $this->get(route('invitations.accept', 'unknown-token'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Invitations/Accept')
                ->where('error', 'not_found')
            );
    }

    public function test_accept_show_returns_error_for_expired_invitation(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $invitation->update(['expires_at' => now()->subHour()]);
        $token = $this->plainToken($invitation);

        $this->get(route('invitations.accept', $token))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('error', 'expired')
            );
    }

    public function test_accept_show_returns_error_for_revoked_invitation(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $invitation->update(['revoked_at' => now()]);
        $token = $this->plainToken($invitation);

        $this->get(route('invitations.accept', $token))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('error', 'revoked')
            );
    }

    public function test_accept_show_returns_error_for_already_accepted_invitation(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $invitation->update(['accepted_at' => now()]);
        $token = $this->plainToken($invitation);

        $this->get(route('invitations.accept', $token))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('error', 'already_accepted')
            );
    }

    public function test_accept_show_renders_with_invitation_data_for_valid_token(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site, [
            'email' => 'test@example.com',
            'role' => 'commerciale',
        ]);
        $token = $this->plainToken($invitation);

        $this->get(route('invitations.accept', $token))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Invitations/Accept')
                ->where('email', 'test@example.com')
                ->where('role', 'commerciale')
                ->where('site_nom', $site->nom)
                ->missing('error')
            );
    }

    // ── AcceptInvitationController::checkPhone ────────────────────────────────

    public function test_check_phone_returns_422_for_unknown_token(): void
    {
        $this->postJson(route('invitations.accept.phone', 'bad-token'), [
            'telephone' => '+224620000001',
        ])->assertStatus(422);
    }

    public function test_check_phone_returns_422_for_expired_invitation(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $invitation->update(['expires_at' => now()->subHour()]);
        $token = $this->plainToken($invitation);

        $this->postJson(route('invitations.accept.phone', $token), [
            'telephone' => '+224620000001',
        ])->assertStatus(422);
    }

    public function test_check_phone_returns_422_for_invalid_phone(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        $this->postJson(route('invitations.accept.phone', $token), [
            'telephone' => 'pas-un-numero',
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Numéro de téléphone invalide.']);
    }

    public function test_check_phone_returns_user_exists_when_phone_already_registered(): void
    {
        User::factory()->create(['telephone' => '+224620000002']);

        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        $this->postJson(route('invitations.accept.phone', $token), [
            'telephone' => '+224620000002',
        ])->assertOk()
            ->assertJson(['status' => 'user_exists']);
    }

    public function test_check_phone_returns_not_found_for_new_phone_and_stores_otp(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        $this->postJson(route('invitations.accept.phone', $token), [
            'telephone' => '+224620000099',
        ])->assertOk()
            ->assertJson(['status' => 'not_found']);

        $this->assertTrue(Session::has('register_otp.+224620000099'));
    }

    // ── AcceptInvitationController::verifyOtp ─────────────────────────────────

    public function test_verify_otp_returns_422_for_wrong_code(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        // Generate OTP first
        $this->postJson(route('invitations.accept.phone', $token), [
            'telephone' => '+224620000010',
        ]);

        $this->postJson(route('invitations.accept.otp', $token), [
            'telephone' => '+224620000010',
            'code' => '00000', // wrong
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Code de vérification incorrect.']);
    }

    public function test_verify_otp_returns_verified_true_for_correct_code(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        $this->postJson(route('invitations.accept.phone', $token), [
            'telephone' => '+224620000011',
        ]);

        $this->postJson(route('invitations.accept.otp', $token), [
            'telephone' => '+224620000011',
            'code' => '12345', // dev fixed code
        ])->assertOk()
            ->assertJson(['verified' => true]);
    }

    // ── AcceptInvitationController::accept ────────────────────────────────────

    public function test_accept_returns_422_when_user_already_authenticated(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        $existingUser = User::factory()->create();

        $this->actingAs($existingUser)
            ->postJson(route('invitations.accept.store', $token), [
                'telephone' => '+224620000050',
                'prenom' => 'Test',
                'nom' => 'User',
                'password' => 'Password123',
            ])->assertStatus(422);
    }

    public function test_accept_returns_422_for_invalid_invitation(): void
    {
        $this->postJson(route('invitations.accept.store', 'bad-token'), [
            'telephone' => '+224620000051',
            'prenom' => 'Test',
            'nom' => 'User',
            'password' => 'Password123',
        ])->assertStatus(422);
    }

    public function test_accept_returns_422_when_otp_not_verified(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        // No OTP verification step performed

        $this->postJson(route('invitations.accept.store', $token), [
            'telephone' => '+224620000052',
            'prenom' => 'Test',
            'nom' => 'Utilisateur',
            'password' => 'Password123',
        ])->assertStatus(422);
    }

    public function test_accept_validates_required_fields(): void
    {
        $org = $this->makeOrg();
        $site = $this->makeSite($org);
        $invitation = $this->makeInvitation($site);
        $token = $this->plainToken($invitation);

        $this->postJson(route('invitations.accept.store', $token), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telephone', 'prenom', 'nom', 'password']);
    }

    public function test_accept_creates_user_assigns_role_attaches_site_and_logs_in(): void
    {
        Mail::fake();

        $org = $this->makeOrg();
        $site = $this->makeSite($org);

        // Ensure the role exists
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $invitation = $this->makeInvitation($site, ['role' => 'manager']);
        $token = $this->plainToken($invitation);

        $phone = '+224620000055';

        // Step 1: check phone
        $this->postJson(route('invitations.accept.phone', $token), [
            'telephone' => $phone,
        ]);

        // Step 2: verify OTP
        $this->postJson(route('invitations.accept.otp', $token), [
            'telephone' => $phone,
            'code' => '12345',
        ]);

        // Step 3: accept
        $response = $this->post(route('invitations.accept.store', $token), [
            'telephone' => $phone,
            'prenom' => 'Fatoumata',
            'nom' => 'DIALLO',
            'password' => 'Secure123',
        ]);

        $response->assertRedirect(route('dashboard'));

        // User was created
        $user = User::where('telephone', $phone)->first();
        $this->assertNotNull($user);
        $this->assertSame($invitation->email, $user->email);
        $this->assertSame($org->id, $user->organization_id);

        // Role assigned
        $this->assertTrue($user->hasRole('manager'));

        // Site attached
        $this->assertTrue($user->sites()->where('site_id', $site->id)->exists());

        // Invitation marked accepted
        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertTrue($invitation->isAccepted());

        // User logged in
        $this->assertAuthenticatedAs($user);
    }
}
