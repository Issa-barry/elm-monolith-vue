<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Enums\StatutImportProduits;
use App\Http\Controllers\Controller;
use App\Models\ImportProduits;
use App\Services\ImportProduits\ImportProduitsRepriseExport;
use Maatwebsite\Excel\Facades\Excel;

class RepriseImportProduitsController extends Controller
{
    /**
     * Fichier de reprise — régénéré à la demande depuis le rapport JSON persisté (jamais stocké
     * en tant que fichier séparé) : reste toujours cohérent avec le résultat réel, rien à
     * nettoyer. Disponible uniquement une fois l'import TERMINE (cf. brief : "générer un fichier
     * téléchargeable" après confirmation réussie).
     */
    public function __invoke(ImportProduits $importProduits)
    {
        $this->authorize('view', $importProduits);

        abort_unless($importProduits->statut === StatutImportProduits::TERMINE, 404);

        return Excel::download(
            new ImportProduitsRepriseExport($importProduits),
            "reprise-import-produits-{$importProduits->id}.xlsx"
        );
    }
}
