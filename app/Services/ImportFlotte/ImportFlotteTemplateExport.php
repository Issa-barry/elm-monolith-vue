<?php

namespace App\Services\ImportFlotte;

use App\Models\Categorie;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportFlotteTemplateExport implements WithMultipleSheets
{
    /** @param  Collection<int, Categorie>  $categories catégories de l'organisation, pour générer les colonnes "capacite__<REFERENCE>" de la feuille "vehicules" — cf. ImportFlotteVehiculesSheetExport. */
    public function __construct(private readonly Collection $categories = new Collection) {}

    public function sheets(): array
    {
        return [
            new ImportFlotteVehiculesSheetExport($this->categories),
            new ImportFlotteLivreursSheetExport,
        ];
    }
}
