<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Enums\StatutImportProduits;
use App\Http\Controllers\Controller;
use App\Models\ImportProduits;
use App\Services\ImportProduits\ImportProduitsExecutor;
use App\Support\Produits\Imports\ImportProduitsStatusProcessor;
use Illuminate\Http\RedirectResponse;

/**
 * Relance un import échoué. Sûr par construction : ImportProduitsExecutor s'exécute dans une
 * transaction globale, donc un échec n'a rien laissé en base — relancer équivaut à une nouvelle
 * confirmation à partir du fichier déjà stocké.
 */
class RetryImportProduitsController extends Controller
{
    public function __invoke(ImportProduits $importProduits, ImportProduitsExecutor $executor): RedirectResponse
    {
        $this->authorize('retry', $importProduits);

        $misAJour = ImportProduits::where('id', $importProduits->id)
            ->where('statut', StatutImportProduits::ECHOUE->value)
            ->update(['statut' => StatutImportProduits::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas en échec.");

        $statutFinal = ImportProduitsStatusProcessor::traiter($importProduits->fresh(), $executor);

        return redirect()->route('produits.imports.show', $importProduits)
            ->with(...ImportProduitsStatusProcessor::messageDeStatut($statutFinal, $importProduits->fresh()));
    }
}
