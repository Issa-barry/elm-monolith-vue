<?php

namespace Tests\Unit;

use App\Http\Middleware\PreventCachingOfDynamicResponses;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PreventCachingOfDynamicResponsesTest extends TestCase
{
    public function test_it_sets_a_strict_no_store_cache_control_by_default(): void
    {
        $middleware = new PreventCachingOfDynamicResponses;

        $response = $middleware->handle(
            Request::create('/backoffice/achats/1'),
            fn () => new Response('ok'),
        );

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    public function test_it_never_overrides_a_cache_control_already_set_by_the_controller(): void
    {
        $middleware = new PreventCachingOfDynamicResponses;

        $response = $middleware->handle(
            Request::create('/client/dashboard'),
            function () {
                $response = new Response('ok');
                $response->headers->set('Cache-Control', 'private, max-age=300');

                return $response;
            },
        );

        // Symfony réordonne les directives alphabétiquement (cf. ResponseHeaderBag::
        // getCacheControlHeader()) : on vérifie le contenu, pas l'ordre exact.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=300', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
    }
}
