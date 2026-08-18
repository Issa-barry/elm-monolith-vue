<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Couvre les scénarios de recette de docs/theming.md : thème global par
 * environnement, validation serveur, permissions, retombée de sécurité.
 */
class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function userWithPermission(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'parametres.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'parametres.update', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['parametres.read', 'parametres.update']);
        $this->attachSite($org, $user);

        return $user;
    }

    private function userWithoutPermission(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $this->attachSite($org, $user);

        return $user;
    }

    // Une organisation sans aucun site force l'onboarding pour tout rôle staff, super_admin
    // compris (cf. AuthRedirects::needsOnboarding, middleware EnsureOrganizationHasSite) : sans
    // site, la route dashboard (protégée par org.site.required) redirige avant même d'atteindre
    // le contrôleur testé.
    private function attachSite(Organization $org, User $user): void
    {
        $site = Site::factory()->for($org)->create();
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    /** Simule la politique verrouillée de production : une seule valeur par axe. */
    private function lockToProductionPolicy(): void
    {
        config([
            'theming.allowed_presets' => ['starter'],
            'theming.allowed_primaries' => ['blue'],
            'theming.allowed_surfaces' => ['slate'],
            'theming.default_preset' => 'starter',
            'theming.default_primary' => 'blue',
            'theming.default_surface' => 'slate',
        ]);
    }

    /** Simule la politique preprod : tout sauf la famille bleue. */
    private function restrictToPreprodPolicy(): void
    {
        config([
            'theming.allowed_presets' => ['starter', 'aura', 'lara', 'material', 'nora'],
            'theming.allowed_primaries' => ['emerald', 'green', 'orange', 'violet', 'rose'],
            'theming.allowed_surfaces' => ['zinc', 'slate', 'stone', 'neutral', 'gray'],
            'theming.default_preset' => 'starter',
            'theming.default_primary' => 'emerald',
            'theming.default_surface' => 'slate',
        ]);
    }

    // ── Recette #1 / #3 : politique restreint ce qui est proposé ──────────────

    public function test_production_policy_only_exposes_blue_as_allowed_primary(): void
    {
        $this->lockToProductionPolicy();
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->get(route('theme.edit'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('theme.allowed.primaries', ['blue'])
                ->where('theme.locked.primary', true)
                ->where('theme.active.primary', 'blue'),
            );
    }

    public function test_preprod_policy_excludes_blue_family_from_allowed_primaries(): void
    {
        $this->restrictToPreprodPolicy();
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $response = $this->actingAs($user)->get(route('theme.edit'));

        $response->assertStatus(200);
        $allowed = $response->viewData('page')['props']['theme']['allowed']['primaries'];

        foreach (['blue', 'sky', 'cyan', 'indigo'] as $forbidden) {
            $this->assertNotContains($forbidden, $allowed);
        }
    }

    // ── Recette #2 : refus serveur d'une valeur interdite ──────────────────────

    public function test_update_rejects_a_primary_outside_the_allowed_list(): void
    {
        $this->lockToProductionPolicy();
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->put(route('theme.update'), [
                'preset' => 'starter',
                'primary' => 'emerald', // interdit en prod
                'surface' => 'slate',
            ])
            ->assertSessionHasErrors('primary');

        $this->assertNull(Parametre::getThemePrimary($org->id));
    }

    public function test_update_rejects_blue_family_color_under_preprod_policy(): void
    {
        $this->restrictToPreprodPolicy();
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->put(route('theme.update'), [
                'preset' => 'starter',
                'primary' => 'sky',
                'surface' => 'slate',
            ])
            ->assertSessionHasErrors('primary');
    }

    public function test_update_accepts_an_allowed_value_and_persists_it(): void
    {
        $this->restrictToPreprodPolicy();
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->put(route('theme.update'), [
                'preset' => 'lara',
                'primary' => 'violet',
                'surface' => 'stone',
            ])
            ->assertRedirect();

        $this->assertSame('lara', Parametre::getThemePreset($org->id));
        $this->assertSame('violet', Parametre::getThemePrimary($org->id));
        $this->assertSame('stone', Parametre::getThemeSurface($org->id));
    }

    // ── Recette #4 : même thème pour tous les utilisateurs de l'environnement ──

    public function test_theme_is_shared_across_users_of_the_same_organization(): void
    {
        $this->restrictToPreprodPolicy();
        $org = Organization::factory()->create();
        $admin = $this->userWithPermission($org);
        $otherUser = $this->userWithoutPermission($org);

        $this->actingAs($admin)->put(route('theme.update'), [
            'preset' => 'aura',
            'primary' => 'orange',
            'surface' => 'gray',
        ])->assertRedirect();

        $this->actingAs($otherUser)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('theme.active.preset', 'aura')
                ->where('theme.active.primary', 'orange')
                ->where('theme.active.surface', 'gray'),
            );
    }

    // ── Recette #5 : rien côté client ne peut écraser le thème serveur ────────

    public function test_client_side_cookies_cannot_influence_the_shared_theme(): void
    {
        $this->lockToProductionPolicy();
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->withCookie('primevue_primary', 'emerald')
            ->withCookie('primevue_surface', 'zinc')
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('theme.active.primary', 'blue')
                ->where('theme.active.surface', 'slate'),
            );
    }

    // ── Recette #8 : fallback du déploiement si rien en base ──────────────────

    public function test_falls_back_to_deployment_default_when_nothing_persisted(): void
    {
        $this->restrictToPreprodPolicy();
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->assertNull(Parametre::getThemePrimary($org->id));

        $this->actingAs($user)
            ->get(route('theme.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('theme.active.primary', 'emerald') // default du déploiement
                ->where('theme.active.preset', 'starter')
                ->where('theme.active.surface', 'slate'),
            );
    }

    // ── Recette #9 : ancienne valeur devenue interdite → retombée sûre ────────

    public function test_stale_persisted_value_no_longer_allowed_falls_back_safely(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        // Choisi pendant que la politique preprod était en vigueur...
        $this->restrictToPreprodPolicy();
        Parametre::setTheme($org->id, 'starter', 'emerald', 'slate');

        // ...puis le déploiement bascule sur la politique verrouillée prod.
        $this->lockToProductionPolicy();

        $this->actingAs($user)
            ->get(route('theme.edit'))
            ->assertInertia(fn ($page) => $page
                // Jamais 'emerald' (devenu interdit) : retombe sur le défaut du
                // déploiement, qui est bien autorisé ici.
                ->where('theme.active.primary', 'blue'),
            );
    }

    // ── Recette #10 : permissions ───────────────────────────────────────────

    public function test_edit_returns_403_without_parametres_read_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);

        $this->actingAs($user)
            ->get(route('theme.edit'))
            ->assertStatus(403);
    }

    public function test_update_returns_403_without_parametres_update_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);

        $this->actingAs($user)
            ->put(route('theme.update'), [
                'preset' => 'starter',
                'primary' => 'blue',
                'surface' => 'slate',
            ])
            ->assertStatus(403);

        $this->assertNull(Parametre::getThemePrimary($org->id));
    }

    public function test_edit_redirects_unauthenticated_user(): void
    {
        $this->get(route('theme.edit'))->assertRedirect(route('login'));
    }

    // ── Visiteur non authentifié (page de login) ───────────────────────────────

    public function test_guest_request_still_resolves_a_theme_via_public_organization(): void
    {
        $this->lockToProductionPolicy();
        Organization::factory()->create();

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page
                ->where('theme.active.primary', 'blue')
                ->where('theme.locked.primary', true),
            );
    }

    // ── Garde-fou : le formulaire de paramètres générique ne doit jamais
    //    pouvoir modifier un paramètre du groupe "theme" ──────────────────────

    public function test_generic_parametre_endpoint_refuses_to_update_a_theme_row(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        Parametre::setTheme($org->id, 'starter', 'blue', 'slate');
        $parametre = Parametre::where('organization_id', $org->id)
            ->where('cle', Parametre::CLE_THEME_PRIMARY)
            ->firstOrFail();

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), ['valeur' => 'lara'])
            ->assertStatus(404);

        $this->assertSame('blue', Parametre::getThemePrimary($org->id));
    }

    public function test_generic_parametres_listing_excludes_theme_group(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        Parametre::setTheme($org->id, 'starter', 'blue', 'slate');

        $this->actingAs($user)
            ->get(route('parametres.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('parametres', fn ($parametres) => collect($parametres)
                    ->doesntContain(fn ($p) => $p['groupe'] === Parametre::GROUPE_THEME),
                ),
            );
    }
}
