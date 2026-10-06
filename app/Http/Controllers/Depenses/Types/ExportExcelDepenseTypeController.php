<?php

namespace App\Http\Controllers\Depenses\Types;

use App\Http\Controllers\Controller;
use App\Models\DepenseType;
use App\Services\DepenseTypes\DepenseTypeListExport;
use App\Support\Depenses\DepenseTypeFilterQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportExcelDepenseTypeController extends Controller
{
    public function __invoke(Request $request)
    {
        // Export aligné sur les mêmes permissions que la création/l'import (cf.
        // brief : « créer, importer ou exporter » relèvent des droits de gestion,
        // pas du simple droit de consultation) — pas de nouvelle permission.
        $this->authorize('create', DepenseType::class);

        $types = DepenseTypeFilterQuery::pour($request);
        $filename = 'types-depense-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new DepenseTypeListExport($types), $filename);
    }
}
