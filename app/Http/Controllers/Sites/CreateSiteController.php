<?php

namespace App\Http\Controllers\Sites;

use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use App\Support\Sites\SiteFormSupport;
use Inertia\Inertia;
use Inertia\Response;

class CreateSiteController extends Controller
{
    public function __invoke(SiteCentralTresorerieResolver $tresorerie): Response
    {
        $this->authorize('create', Site::class);

        return Inertia::render('Sites/Create', [
            'types' => SiteType::options(),
            'tresorerie_principale' => SiteFormSupport::tresoreriePrincipale($tresorerie, auth()->user()->organization_id),
        ]);
    }
}
