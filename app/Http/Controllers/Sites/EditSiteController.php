<?php

namespace App\Http\Controllers\Sites;

use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Sites\SiteDataFormatter;
use Inertia\Inertia;
use Inertia\Response;

class EditSiteController extends Controller
{
    public function __invoke(Site $site): Response
    {
        $this->authorize('update', $site);

        $site->load('parent');

        return Inertia::render('Sites/Edit', [
            'site' => SiteDataFormatter::pour($site),
            'types' => SiteType::options(),
        ]);
    }
}
