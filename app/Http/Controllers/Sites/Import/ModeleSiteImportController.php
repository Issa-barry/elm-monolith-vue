<?php

namespace App\Http\Controllers\Sites\Import;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\ImportSites\SiteImportTemplateExport;
use Maatwebsite\Excel\Facades\Excel;

class ModeleSiteImportController extends Controller
{
    public function __invoke(): mixed
    {
        abort_if(! auth()->user()->can('create', Site::class), 403);

        return Excel::download(new SiteImportTemplateExport, 'modele-import-sites.xlsx');
    }
}
