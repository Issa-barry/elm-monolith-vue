<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportRh;
use App\Services\ImportRh\ImportRhPresenter;
use Inertia\Inertia;
use Inertia\Response;

/** Aperçu ou rapport d'un import : montants de salaire masqués sans rh-remunerations.read. */
class ShowImportRhController extends Controller
{
    public function __invoke(ImportRh $importRh, ImportRhPresenter $presenter): Response
    {
        $this->authorize('view', $importRh);
        // Contrôle explicite : le Gate::before du super administrateur ne vérifie pas l'organisation.
        abort_if($importRh->organization_id !== auth()->user()->organization_id, 403);

        $importRh->load('user');

        return Inertia::render('ImportsRh/Show', [
            'record' => $presenter->detail($importRh, auth()->user()),
            'peut_confirmer' => auth()->user()->can('confirm', $importRh),
        ]);
    }
}
