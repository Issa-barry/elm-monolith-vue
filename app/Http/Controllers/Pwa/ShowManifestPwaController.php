<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ShowManifestPwaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'id' => '/',
            'name' => config('pwa.name'),
            'short_name' => config('pwa.short_name'),
            'description' => 'Back-office Eau La Maman : ventes, stocks, livraisons et paiements.',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#fafafa',
            'theme_color' => '#fafafa',
            'lang' => 'fr',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            // Un changement de nom doit atteindre les téléphones sans attendre l'expiration d'un cache.
            'Cache-Control' => 'no-cache',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
