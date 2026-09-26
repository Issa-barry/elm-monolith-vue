<?php

namespace App\Http\Controllers\Depenses\Types\Import;

use App\Http\Controllers\Controller;
use App\Models\DepenseType;
use App\Services\ImportDepenseTypes\DepenseTypeImportTemplateExport;
use Maatwebsite\Excel\Facades\Excel;

class ModeleDepenseTypeImportController extends Controller
{
    public function __invoke(): mixed
    {
        abort_if(! auth()->user()->can('create', DepenseType::class), 403);

        return Excel::download(new DepenseTypeImportTemplateExport, 'modele-import-types-depense.xlsx');
    }
}
