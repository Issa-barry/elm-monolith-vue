<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportProduits;
use App\Support\Produits\Imports\ImportProduitsFormatter;
use Inertia\Inertia;
use Inertia\Response;

class IndexImportProduitsController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', ImportProduits::class);

        $imports = ImportProduits::with(['user:id,personne_id', 'user.personne'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ImportProduits $i) => ImportProduitsFormatter::row($i));

        return Inertia::render('ImportsProduits/Index', [
            'imports' => $imports,
        ]);
    }
}
