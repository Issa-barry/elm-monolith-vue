<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Sites\SiteDataFormatter;
use Inertia\Inertia;
use Inertia\Response;

class IndexSiteController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', Site::class);

        $sites = Site::with(['parent'])
            ->withCount(['enfants', 'users as membres_count', 'vehicules'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Site $s) => [
                ...SiteDataFormatter::pour($s),
                'membres_count' => (int) $s->membres_count,
                'vehicules_count' => (int) $s->vehicules_count,
            ]);

        return Inertia::render('Sites/Index', [
            'sites' => $sites,
        ]);
    }
}
