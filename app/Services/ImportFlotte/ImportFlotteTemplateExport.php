<?php

namespace App\Services\ImportFlotte;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportFlotteTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new ImportFlotteVehiculesSheetExport,
            new ImportFlotteLivreursSheetExport,
        ];
    }
}
