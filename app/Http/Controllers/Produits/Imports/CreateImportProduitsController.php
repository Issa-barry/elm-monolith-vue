<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportProduits;
use Inertia\Inertia;
use Inertia\Response;

class CreateImportProduitsController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('create', ImportProduits::class);

        return Inertia::render('ImportsProduits/Create');
    }
}
