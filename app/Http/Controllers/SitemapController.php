<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $baseUrl = rtrim(config('app.url'), '/');

        $urls = collect(config('seo.sitemap'))
            ->map(fn (array $entry) => [
                'loc' => $baseUrl.$entry['path'],
                'priority' => $entry['priority'],
                'changefreq' => $entry['changefreq'],
            ]);

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
