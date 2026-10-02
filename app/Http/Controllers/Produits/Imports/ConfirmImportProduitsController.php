<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Enums\StatutImportProduits;
use App\Http\Controllers\Controller;
use App\Models\ImportProduits;
use App\Services\ImportProduits\ImportProduitsExecutor;
use App\Support\Produits\Imports\ImportProduitsStatusProcessor;
use Illuminate\Http\RedirectResponse;

/**
 * Traitement synchrone (pas de file d'attente) — même choix qu'ImportFlotteController, volume
 * comparable (plafonné à 500 lignes par ImportProduitsParser::MAX_LIGNES).
 */
class ConfirmImportProduitsController extends Controller
{
    public function __invoke(ImportProduits $importProduits, ImportProduitsExecutor $executor): RedirectResponse
    {
        $this->authorize('confirm', $importProduits);

        abort_unless($importProduits->estPret(), 422, "Cet import n'est pas prêt à être confirmé (déjà confirmé, groupes en erreur, ou fichier déjà importé).");

        // Update conditionnel atomique (et non lecture d'estPret() puis écriture séparée) : deux
        // confirmations quasi simultanées (double-clic, deux onglets) ne peuvent pas toutes les
        // deux passer — une seule requête UPDATE peut affecter la ligne tant qu'elle est encore
        // au statut "analyse" (même mécanisme qu'ImportFlotteController::confirm()).
        $misAJour = ImportProduits::where('id', $importProduits->id)
            ->where('statut', StatutImportProduits::ANALYSE->value)
            ->where('nb_lignes_erreur', 0)
            ->update(['statut' => StatutImportProduits::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas prêt à être confirmé (déjà confirmé, ou groupes en erreur).");

        $statutFinal = ImportProduitsStatusProcessor::traiter($importProduits->fresh(), $executor);

        return redirect()->route('produits.imports.show', $importProduits)
            ->with(...ImportProduitsStatusProcessor::messageDeStatut($statutFinal, $importProduits->fresh()));
    }
}
