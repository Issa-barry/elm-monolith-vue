<?php

namespace Tests\Feature\OpenApi;

use App\Models\Organization;
use App\Models\User;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vérifications automatiques du chantier OpenAPI/Swagger (dedoc/scramble,
 * cf. rapport du 27/08/2026) — génère les documents FRAIS à chaque exécution
 * (jamais depuis le fichier exporté sur disque, qui peut être périmé) pour
 * détecter une régression le jour où le code change mais que la doc,
 * elle, ne suit pas.
 */
class OpenApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    private function generate(string $api = 'default'): array
    {
        return app(Generator::class)(Scramble::getGeneratorConfig($api));
    }

    public function test_default_api_generates_a_valid_openapi_document(): void
    {
        $doc = $this->generate('default');

        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('Eau La Maman API', $doc['info']['title']);
        $this->assertNotEmpty($doc['paths']);
    }

    public function test_vitrine_api_generates_a_valid_openapi_document(): void
    {
        $doc = $this->generate('vitrine');

        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertNotEmpty($doc['paths']);
    }

    public function test_default_api_declares_bearer_sanctum_security_scheme(): void
    {
        $doc = $this->generate('default');

        $schemes = $doc['components']['securitySchemes'] ?? [];
        $bearer = collect($schemes)->first(fn ($s) => ($s['type'] ?? null) === 'http' && ($s['scheme'] ?? null) === 'bearer');

        $this->assertNotNull($bearer, 'Expected an http/bearer security scheme on the default API.');
        $this->assertSame('Sanctum', $bearer['bearerFormat'] ?? null);
    }

    public function test_vitrine_api_declares_api_key_security_scheme_only(): void
    {
        $doc = $this->generate('vitrine');

        $schemes = $doc['components']['securitySchemes'] ?? [];

        $this->assertCount(1, $schemes, 'The public (vitrine) API must expose exactly one security scheme.');
        $scheme = collect($schemes)->first();
        $this->assertSame('apiKey', $scheme['type']);
        $this->assertSame('X-Vitrine-Key', $scheme['name']);
        $this->assertSame('header', $scheme['in']);
    }

    public function test_login_and_registration_routes_do_not_require_authentication(): void
    {
        $doc = $this->generate('default');

        foreach (['/auth/login', '/auth/register', '/auth/password/reset'] as $path) {
            $this->assertArrayHasKey($path, $doc['paths'], "Missing documented path: $path");
            $method = array_key_first($doc['paths'][$path]);
            $this->assertSame(
                [],
                $doc['paths'][$path][$method]['security'] ?? null,
                "$path should be marked as not requiring authentication (security: [])."
            );
        }
    }

    public function test_protected_client_routes_require_authentication(): void
    {
        $doc = $this->generate('default');

        foreach (['/v1/mobile/dashboard', '/v1/mobile/depenses/mine', '/v1/mobile/activite'] as $path) {
            $this->assertArrayHasKey($path, $doc['paths'], "Missing documented path: $path");
            $method = array_key_first($doc['paths'][$path]);
            // Pas de clé 'security' locale = hérite du security global (bearer) du document.
            $this->assertArrayNotHasKey('security', $doc['paths'][$path][$method]);
        }
    }

    /**
     * Périmètre initial (cf. rapport) : seules les routes Nuxt/mobile client sont
     * documentées — pas l'API mobile staff (v1/backoffice), jamais les pages
     * Inertia (client/*, backoffice/*), jamais les routes de la vitrine (elles
     * vivent dans le document "vitrine" séparé, testé plus bas).
     */
    public function test_default_api_excludes_backoffice_and_vitrine_and_web_routes(): void
    {
        $doc = $this->generate('default');

        foreach (array_keys($doc['paths']) as $path) {
            $this->assertStringNotContainsString('/v1/backoffice', $path);
            $this->assertStringNotContainsString('/public/', $path);
        }
    }

    public function test_vitrine_api_only_contains_vitrine_routes(): void
    {
        $doc = $this->generate('vitrine');

        foreach (array_keys($doc['paths']) as $path) {
            $this->assertStringStartsWith('/public/', $path, "Unexpected path in the vitrine API: $path");
        }

        $this->assertArrayHasKey('/public/contact', $doc['paths']);
        $this->assertArrayHasKey('/public/modules', $doc['paths']);
    }

    public function test_key_lot_1_to_5_endpoints_are_documented(): void
    {
        $doc = $this->generate('default');

        $expected = [
            '/v1/mobile/dashboard',
            '/v1/mobile/depenses/mine',
            '/v1/mobile/activite',
            '/v1/mobile/commandes/mine',
            '/v1/mobile/commandes/{commandeId}',
            '/v1/mobile/propositions-vehicules',
            '/v1/mobile/profile',
            '/v1/mobile/vehicules/mine',
        ];

        foreach ($expected as $path) {
            $this->assertArrayHasKey($path, $doc['paths'], "Missing documented path: $path");
        }
    }

    public function test_endpoints_are_grouped_by_tag_not_left_flat(): void
    {
        $doc = $this->generate('default');

        $tags = collect($doc['paths'])
            ->flatMap(fn ($methods) => collect($methods)->pluck('tags')->flatten())
            ->unique()
            ->values();

        // Plus d'une poignée de tags distincts = vraiment groupé, pas une liste plate.
        $this->assertGreaterThan(5, $tags->count());
        $this->assertTrue($tags->contains('Dashboard'));
        $this->assertTrue($tags->contains('Authentication'));
    }

    public function test_no_real_secret_appears_in_either_document(): void
    {
        foreach (['default', 'vitrine'] as $api) {
            $json = json_encode($this->generate($api));

            $this->assertStringNotContainsString((string) config('services.vitrine.token'), $json ?: '');
            $this->assertStringNotContainsString((string) config('app.key'), $json ?: '');
            $this->assertStringNotContainsString('VITRINE_SERVICE_TOKEN=', $json ?: '');
        }
    }

    /**
     * Scénario H (cf. rapport du 27/08/2026) : une installation qui NE DÉFINIT PAS
     * `API_DOCS_ENABLED` doit avoir la doc fermée. On ne force ici AUCUNE valeur de
     * config — .env.testing ne définit pas cette variable, donc ce test exerce le
     * vrai défaut de `config/scramble.php` (`env('API_DOCS_ENABLED', false)`), pas
     * une valeur simulée.
     */
    public function test_docs_are_closed_by_default_without_explicit_configuration(): void
    {
        $this->get('/docs/api')->assertNotFound();
    }

    /**
     * Scénario A : `API_DOCS_ENABLED=false` doit fermer la doc MÊME EN LOCAL, où
     * `RestrictedDocsAccess` natif de Scramble laisse normalement tout passer sans
     * authentification. `EnsureApiDocsEnabled` est placé AVANT dans la chaîne de
     * middleware : il coupe la requête avant que ce comportement natif ne s'applique.
     */
    public function test_docs_are_disabled_in_local_environment_when_flag_is_false(): void
    {
        config(['scramble.docs_enabled' => false]);
        $this->app->instance('env', 'local');
        $this->assertTrue(app()->isLocal());

        $this->get('/docs/api')->assertNotFound();
        $this->getJson('/docs/api.json')->assertNotFound();
        $this->get('/docs/vitrine')->assertNotFound();
        $this->getJson('/docs/vitrine.json')->assertNotFound();
    }

    /**
     * Scénarios B/C/D/E : `API_DOCS_ENABLED=false` (App\Http\Middleware\EnsureApiDocsEnabled)
     * est un coupe-circuit global, prioritaire sur le Gate `viewApiDocs` — un staff par
     * ailleurs autorisé doit quand même être bloqué sur les 4 URLs (404, pas 403 : la doc
     * "n'existe pas" plutôt que "vous n'y avez pas droit"). Existe car préprod et prod
     * partagent APP_ENV=production : l'environnement seul ne permet pas de fermer la doc
     * sur l'un sans la fermer sur l'autre.
     */
    public function test_docs_are_fully_disabled_when_api_docs_enabled_is_false(): void
    {
        config(['scramble.docs_enabled' => false]);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $this->actingAs($user)->get('/docs/api')->assertNotFound();
        $this->actingAs($user)->getJson('/docs/api.json')->assertNotFound();
        $this->actingAs($user)->get('/docs/vitrine')->assertNotFound();
        $this->actingAs($user)->getJson('/docs/vitrine.json')->assertNotFound();
    }

    /**
     * Scénario G (partie 1) : flag activé, mais hors environnement local et sans
     * utilisateur authentifié — le Gate `viewApiDocs` doit refuser, comme avant
     * l'introduction du flag (403, pas 404 : la doc existe, l'accès est juste refusé).
     */
    public function test_docs_ui_is_open_without_authentication_only_in_local_environment(): void
    {
        config(['scramble.docs_enabled' => true]);

        // phpunit.xml force APP_ENV=testing (jamais local) — cette requête doit
        // donc être refusée par le Gate `viewApiDocs`, pas laissée ouverte.
        $this->assertNotSame('local', app()->environment());

        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/vitrine')->assertForbidden();
    }

    /**
     * Scénario F : flag activé + staff autorisé hors local → doc accessible. Le flag
     * n'est qu'une première barrière ; le Gate `viewApiDocs` reste la seconde,
     * inchangée.
     */
    public function test_docs_ui_is_accessible_to_an_authenticated_staff_member_outside_local(): void
    {
        config(['scramble.docs_enabled' => true]);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $this->actingAs($user)->get('/docs/api')->assertOk();
    }

    /**
     * Scénario G (partie 2) : flag activé + utilisateur authentifié mais non-staff
     * (rôle client pur) → toujours refusé par le Gate `viewApiDocs`, le flag ne
     * remplace pas cette autorisation applicative.
     */
    public function test_docs_ui_rejects_a_pure_client_account_outside_local(): void
    {
        config(['scramble.docs_enabled' => true]);

        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        $this->actingAs($user)->get('/docs/api')->assertForbidden();
    }
}
