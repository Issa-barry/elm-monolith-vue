<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\UserAuthIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Non-régression pour l'incident 2026-08-25 : un GET direct (navigation navigateur
 * normale, sans header X-Inertia) sur une page authentifiée affichait le payload
 * JSON Inertia brut au lieu du HTML/Vue — signe qu'un cache intermédiaire pouvait
 * mémoriser la variante JSON d'une navigation Inertia (SPA) et la resservir à une
 * navigation directe ultérieure. Cf. PreventCachingOfDynamicResponses et
 * HandleInertiaRequests::authUserPayload().
 */
class InertiaResponseSecurityTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['type-vehicules.read']);
    }

    // ── Rendu : jamais de JSON brut sur une navigation directe ──────────────────

    public function test_direct_get_navigation_returns_html_never_raw_json(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('type-vehicules.index'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertDontSee('"component":"TypeVehicules/Index"', false);
        // Le payload Inertia doit être embarqué dans l'attribut data-page du HTML,
        // jamais servi comme corps de réponse JSON pur.
        $response->assertSee('data-page=', false);
    }

    public function test_direct_get_navigation_response_is_never_cacheable_by_an_intermediate_cache(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('type-vehicules.index'));

        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertNotNull($cacheControl, 'Cache-Control doit toujours être présent sur une réponse authentifiée.');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_inertia_xhr_navigation_still_returns_proper_inertia_json(): void
    {
        // Une navigation Inertia (SPA) réelle envoie toujours sa version d'assets
        // connue — l'omettre déclenche à raison un 409 (rechargement complet requis),
        // cf. HandleInertiaRequests::version(). On la calcule pour simuler une vraie
        // navigation interne, pas un premier chargement de page.
        $version = (new HandleInertiaRequests)->version(request());

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get(route('type-vehicules.index'));

        fwrite(STDERR, "\n[DEBUG version] ".var_export($version, true)."\n");
        fwrite(STDERR, '[DEBUG status] '.$response->getStatusCode()."\n");
        fwrite(STDERR, '[DEBUG content-type] '.$response->headers->get('Content-Type')."\n");
        fwrite(STDERR, '[DEBUG body] '.substr($response->getContent(), 0, 500)."\n");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('X-Inertia', 'true');
        $response->assertInertia(fn ($page) => $page->component('TypeVehicules/Index'));
    }

    public function test_default_cache_control_is_the_strict_no_store_variant(): void
    {
        $response = $this->actingAs($this->user)->get(route('type-vehicules.index'));
        $cacheControl = $response->headers->get('Cache-Control');

        // Symfony réordonne les directives alphabétiquement (cf. ResponseHeaderBag::
        // getCacheControlHeader()) : on vérifie le contenu, pas l'ordre exact.
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    // ── auth.user : liste blanche, jamais le modèle brut ────────────────────────

    public function test_shared_auth_user_prop_exposes_only_the_documented_frontend_contract(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('type-vehicules.index'));

        $response->assertInertia(function ($page) {
            $user = $page->toArray()['props']['auth']['user'];

            $this->assertEqualsCanonicalizing(
                ['id', 'prenom', 'nom', 'name', 'email', 'telephone', 'email_verified_at', 'created_at', 'updated_at', 'organization'],
                array_keys($user),
                'auth.user ne doit exposer que le contrat documenté (resources/js/types/index.d.ts).',
            );

            $forbidden = [
                'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
                'two_factor_confirmed_at', 'personne', 'personne_id', 'auth_identities',
                'authIdentities', 'roles', 'permissions', 'matricule', 'expo_push_token',
                'must_change_password', 'status', 'is_active', 'organization_id', 'deleted_at',
            ];
            foreach ($forbidden as $key) {
                $this->assertArrayNotHasKey($key, $user, "auth.user ne doit jamais contenir la clé « {$key} ».");
            }

            $organization = $user['organization'];
            $this->assertEqualsCanonicalizing(
                ['id', 'name', 'slug', 'logo_url'],
                array_keys($organization),
                'auth.user.organization ne doit exposer que id/name/slug/logo_url.',
            );

            $forbiddenOrgKeys = [
                'siret', 'code', 'domaine_activite', 'is_active', 'next_produit_reference',
                'proprietaire_interne_id', 'logo_path', 'deleted_at',
            ];
            foreach ($forbiddenOrgKeys as $key) {
                $this->assertArrayNotHasKey($key, $organization, "auth.user.organization ne doit jamais contenir la clé « {$key} ».");
            }
        });
    }

    public function test_verification_token_is_never_serialized_on_user_auth_identity(): void
    {
        $identity = UserAuthIdentity::create([
            'user_id' => $this->user->id,
            'type' => UserAuthIdentity::TYPE_EMAIL,
            'value' => 'test-otp@example.com',
            'normalized_value' => 'test-otp@example.com',
            'verification_token' => 'super-secret-otp-token',
            'is_primary' => false,
        ]);

        $array = $identity->toArray();

        $this->assertArrayNotHasKey('verification_token', $array);
    }
}
