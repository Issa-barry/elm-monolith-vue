<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Http\Controllers\Controller;
use App\Services\ImportProduits\ImportProduitsTemplateExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TemplateImportProduitsController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_if(! $request->user()->can('imports-produits.create'), 403);

        $orgId = $request->user()->organization_id;

        return Excel::download(new ImportProduitsTemplateExport($orgId), 'modele-import-produits.xlsx');
    }
}
