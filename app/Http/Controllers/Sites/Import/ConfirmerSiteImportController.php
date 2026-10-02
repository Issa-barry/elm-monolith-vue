<?php

namespace App\Http\Controllers\Sites\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteImportRequest;
use App\Services\ImportSites\SiteImportExecutor;
use App\Support\Sites\SiteImportAnalyseFormatter;
use Illuminate\Http\JsonResponse;

class ConfirmerSiteImportController extends Controller
{
    public function __invoke(StoreSiteImportRequest $request, SiteImportExecutor $executor): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $resultat = $executor->executer($request->file('fichier')->getRealPath(), $orgId);

        $reponse = SiteImportAnalyseFormatter::pour($resultat['analyse']);
        $reponse['execute'] = $resultat['succes'];
        if ($resultat['succes']) {
            $reponse['crees'] = $resultat['compteurs']['crees'];
            $reponse['mis_a_jour'] = $resultat['compteurs']['mis_a_jour'];
            $reponse['existants_ignores'] = $resultat['compteurs']['existants_ignores'];
        }

        return response()->json($reponse);
    }
}
