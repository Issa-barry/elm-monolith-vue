<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Enums\StatutImportRh;
use App\Http\Controllers\Controller;
use App\Models\ImportRh;
use App\Services\ImportRh\ImportRhService;
use Illuminate\Http\RedirectResponse;

/**
 * Confirmation : passage atomique « analysé → en cours » (deux confirmations simultanées ne peuvent
 * pas passer toutes les deux), puis nouvelle analyse et application tout ou rien.
 */
class ConfirmImportRhController extends Controller
{
    public function __invoke(ImportRh $importRh, ImportRhService $imports): RedirectResponse
    {
        $this->authorize('confirm', $importRh);
        abort_if($importRh->organization_id !== auth()->user()->organization_id, 403);

        $passe = ImportRh::whereKey($importRh->id)
            ->where('statut', StatutImportRh::ANALYSE->value)
            ->where('nb_lignes_erreur', 0)
            ->whereNotNull('fichier_path')
            ->update(['statut' => StatutImportRh::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($passe === 1, 422, "Cet import n'est pas prêt à être confirmé (déjà traité, abandonné, ou lignes en erreur).");

        $statut = $imports->traiter($importRh->fresh(), auth()->user());
        $import = $importRh->fresh();

        return redirect()->route('employes.imports.show', $importRh)->with(...match ($statut) {
            StatutImportRh::TERMINE => ['success', sprintf(
                'Import terminé : %d salarié(s) mis à jour, %d contrat(s) créé(s).',
                $import->nb_employes_mis_a_jour ?? 0,
                $import->nb_contrats_crees ?? 0,
            )],
            default => ['error', "L'import a échoué — aucune donnée n'a été enregistrée, voir le détail ci-dessous."],
        });
    }
}
