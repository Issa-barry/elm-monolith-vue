<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Page d'erreur Inertia (Errors/Error) ouverte directement (favori, lien collé, F5) : elle doit
 * porter le prop `theme`, sans lequel app.ts ne peut pas démarrer et la page reste blanche.
 * Les props partagées par HandleInertiaRequests manquent quand l'erreur survient avant ce
 * middleware (route inconnue, modèle introuvable), d'où ce contrôle dédié.
 */
class PageErreurThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_une_route_inconnue_rend_la_page_d_erreur_avec_le_theme(): void
    {
        config([
            'theming.allowed_presets' => ['starter'],
            'theming.allowed_primaries' => ['blue'],
            'theming.allowed_surfaces' => ['slate'],
            'theming.default_preset' => 'starter',
            'theming.default_primary' => 'blue',
            'theming.default_surface' => 'slate',
        ]);

        $this->get('/page-qui-n-existe-pas')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Errors/Error')
                ->where('status', 404)
                ->where('theme.active.preset', 'starter')
                ->where('theme.active.primary', 'blue')
                ->where('theme.active.surface', 'slate')
                ->has('theme.allowed')
                ->has('theme.locked'));
    }

    public function test_une_route_inconnue_en_navigation_inertia_ne_peut_pas_etre_resservie_en_json_brut(): void
    {
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ])->get('/backoffice/page-qui-n-existe-pas');

        $response->assertNotFound()
            ->assertHeader('X-Inertia', 'true')
            ->assertHeader('Vary', 'X-Inertia')
            ->assertJsonPath('component', 'Errors/Error')
            ->assertJsonPath('props.status', 404);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_une_route_inconnue_ouverte_directement_rend_la_page_html_sans_cache(): void
    {
        $response = $this->get('/backoffice/page-qui-n-existe-pas');

        $response->assertNotFound()
            ->assertHeader('Vary', 'X-Inertia')
            ->assertHeaderMissing('X-Inertia')
            ->assertViewIs('app');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_un_modele_introuvable_rend_la_page_d_erreur_avec_le_theme_de_l_organisation(): void
    {
        $org = Organization::factory()->create();
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $site = Site::factory()->for($org)->create();
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)
            ->get('/backoffice/proprietaires/01kxtcmg9nqemejww53jdqd3k0')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Errors/Error')
                ->where('status', 404)
                ->has('theme.active.preset')
                ->has('theme.active.primary')
                ->has('theme.active.surface'));
    }
}
