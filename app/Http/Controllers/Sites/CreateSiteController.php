<?php

namespace App\Http\Controllers\Sites;

use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Inertia\Inertia;
use Inertia\Response;

class CreateSiteController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('create', Site::class);

        return Inertia::render('Sites/Create', [
            'types' => SiteType::options(),
        ]);
    }
}
