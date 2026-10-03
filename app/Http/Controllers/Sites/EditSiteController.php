<?php

namespace App\Http\Controllers\Sites;

use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use App\Support\Sites\SiteDataFormatter;
use App\Support\Sites\SiteFormSupport;
use Inertia\Inertia;
use Inertia\Response;

class EditSiteController extends Controller
{
    public function __invoke(Site $site, SiteCentralTresorerieResolver $tresorerie): Response
    {
        $this->authorize('update', $site);

        $site->load('parent');

        return Inertia::render('Sites/Edit', [
            'site' => SiteDataFormatter::pour($site),
            'types' => SiteType::options(),
            'tresorerie_principale' => SiteFormSupport::tresoreriePrincipale($tresorerie, $site->organization_id),
        ]);
    }
}
