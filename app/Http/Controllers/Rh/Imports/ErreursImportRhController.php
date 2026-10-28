<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportRh;
use App\Services\ImportRh\ImportRhErreursExport;
use App\Services\ImportRh\ImportRhPresenter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Fichier des lignes en erreur, à corriger puis redéposer (salaire seulement avec la permission). */
class ErreursImportRhController extends Controller
{
    public function __invoke(ImportRh $importRh, ImportRhPresenter $presenter): BinaryFileResponse
    {
        $this->authorize('view', $importRh);
        abort_if($importRh->organization_id !== auth()->user()->organization_id, 403);

        $user = auth()->user();

        return Excel::download(
            new ImportRhErreursExport($presenter->lignesEnErreur($importRh, $user), $user->can('rh-remunerations.read')),
            'import-rh-erreurs-'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
