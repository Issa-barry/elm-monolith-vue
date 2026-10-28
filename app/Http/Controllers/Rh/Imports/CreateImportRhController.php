<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportRh;
use Inertia\Inertia;
use Inertia\Response;

class CreateImportRhController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('create', ImportRh::class);

        return Inertia::render('ImportsRh/Create', [
            // Indique seulement si la colonne salaire figure dans le fichier pré-rempli.
            'avec_salaire' => auth()->user()->can('rh-remunerations.read'),
            'peut_creer_contrat' => auth()->user()->can('rh-contrats.create'),
        ]);
    }
}
