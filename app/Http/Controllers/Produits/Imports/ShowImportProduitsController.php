<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportProduits;
use App\Support\Produits\Imports\ImportProduitsFormatter;
use Inertia\Inertia;
use Inertia\Response;

class ShowImportProduitsController extends Controller
{
    public function __invoke(ImportProduits $importProduits): Response
    {
        $this->authorize('view', $importProduits);

        return Inertia::render('ImportsProduits/Show', [
            'record' => ImportProduitsFormatter::detail($importProduits),
        ]);
    }
}
