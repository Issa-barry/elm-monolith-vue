<?php

namespace App\Http\Controllers\Depenses\Types\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepenseTypeImportRequest;
use App\Services\ImportDepenseTypes\DepenseTypeImportExecutor;
use App\Support\Depenses\DepenseTypeImportAnalyseFormatter;
use Illuminate\Http\JsonResponse;

class ConfirmerDepenseTypeImportController extends Controller
{
    public function __invoke(StoreDepenseTypeImportRequest $request, DepenseTypeImportExecutor $executor): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $resultat = $executor->executer($request->file('fichier')->getRealPath(), $orgId);

        $reponse = DepenseTypeImportAnalyseFormatter::pour($resultat['analyse']);
        $reponse['execute'] = $resultat['succes'];
        if ($resultat['succes']) {
            $reponse['crees'] = $resultat['compteurs']['crees'];
        }

        return response()->json($reponse);
    }
}
