<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * Régression du 23/10/2026 : au chargement complet de Ventes (onglet dupliqué, retour navigateur),
 * l'en-tête Link listait ~108 fichiers (~10 Ko) et le serveur de prod/formation renvoyait un 500
 * sans trace Laravel. L'en-tête reste borné quel que soit le nombre de dépendances de la page.
 */
class LinkHeaderPrechargementTest extends TestCase
{
    use RefreshDatabase;

    public function test_en_tete_link_borne_meme_pour_une_page_aux_nombreuses_dependances(): void
    {
        $assets = [];
        for ($i = 0; $i < 108; $i++) {
            $assets["https://fello.eau-la-maman.com/build/assets/chunk-{$i}-AbCdEf12.js"] = ['rel="modulepreload"'];
        }
        Vite::partialMock()->shouldReceive('preloadedAssets')->andReturn($assets);

        Route::middleware('web')->get('/_test/link-header', fn () => response('ok'));

        $link = $this->get('/_test/link-header')->assertOk()->headers->get('Link');

        $this->assertNotNull($link);
        $this->assertCount(20, explode(', ', $link));
        $this->assertLessThan(4096, strlen($link));
    }
}
