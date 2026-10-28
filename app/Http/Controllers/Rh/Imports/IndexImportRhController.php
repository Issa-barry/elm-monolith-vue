<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportRh;
use App\Services\ImportRh\ImportRhPresenter;
use Inertia\Inertia;
use Inertia\Response;

/** Historique des imports de dossiers RH de l'organisation (compteurs seulement, aucun montant). */
class IndexImportRhController extends Controller
{
    public function __invoke(ImportRhPresenter $presenter): Response
    {
        $this->authorize('viewAny', ImportRh::class);

        $imports = ImportRh::with('user')
            ->where('organization_id', auth()->user()->organization_id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ImportRh $i) => $presenter->resume($i));

        return Inertia::render('ImportsRh/Index', [
            'imports' => $imports,
            'peut_importer' => auth()->user()->can('create', ImportRh::class),
        ]);
    }
}
