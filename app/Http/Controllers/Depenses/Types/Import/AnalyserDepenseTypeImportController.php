<?php

namespace App\Http\Controllers\Depenses\Types\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepenseTypeImportRequest;
use App\Services\ImportDepenseTypes\DepenseTypeImportParser;
use App\Support\Depenses\DepenseTypeImportAnalyseFormatter;
use Illuminate\Http\JsonResponse;

class AnalyserDepenseTypeImportController extends Controller
{
    public function __invoke(StoreDepenseTypeImportRequest $request, DepenseTypeImportParser $parser): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $analyse = $parser->analyserFichier($request->file('fichier')->getRealPath(), $orgId);

        return response()->json(DepenseTypeImportAnalyseFormatter::pour($analyse));
    }
}
