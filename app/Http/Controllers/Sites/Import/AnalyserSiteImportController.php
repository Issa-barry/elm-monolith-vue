<?php

namespace App\Http\Controllers\Sites\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteImportRequest;
use App\Services\ImportSites\SiteImportParser;
use App\Support\Sites\SiteImportAnalyseFormatter;
use Illuminate\Http\JsonResponse;

class AnalyserSiteImportController extends Controller
{
    public function __invoke(StoreSiteImportRequest $request, SiteImportParser $parser): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $analyse = $parser->analyserFichier($request->file('fichier')->getRealPath(), $orgId);

        return response()->json(SiteImportAnalyseFormatter::pour($analyse));
    }
}
