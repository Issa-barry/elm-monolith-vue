<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Enums\StatutImportRh;
use App\Http\Controllers\Controller;
use App\Models\ImportRh;
use App\Services\ImportRh\ImportRhService;
use Illuminate\Http\RedirectResponse;

/** Abandon d'un import en attente de confirmation : rien n'est appliqué, le fichier est supprimé. */
class AbandonnerImportRhController extends Controller
{
    public function __invoke(ImportRh $importRh, ImportRhService $imports): RedirectResponse
    {
        $this->authorize('confirm', $importRh);
        abort_if($importRh->organization_id !== auth()->user()->organization_id, 403);
        abort_unless($importRh->statut === StatutImportRh::ANALYSE, 422, 'Seul un import en attente de confirmation peut être abandonné.');

        $imports->abandonner($importRh);

        return redirect()->route('employes.imports.show', $importRh)
            ->with('success', 'Import abandonné : aucune donnée n\'a été modifiée et le fichier a été supprimé.');
    }
}
