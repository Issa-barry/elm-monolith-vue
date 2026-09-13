<?php

namespace App\Support\Depenses;

/**
 * Formate le résultat d'analyse d'un import de types de dépense (compteurs + lignes) — extrait de
 * DepenseTypeImportController::toResponse(), partagé entre l'aperçu (analyser) et la confirmation
 * (confirmer) puisque cette dernière ré-analyse toujours le fichier avant d'exécuter.
 */
final class DepenseTypeImportAnalyseFormatter
{
    public static function pour(array $analyse): array
    {
        $lignes = $analyse['lignes'];
        $nbErreur = count(array_filter($lignes, fn ($l) => $l['statut'] === 'erreur'));

        return [
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            'nb_nouveaux' => count($lignes) - $nbErreur,
            'nb_erreurs' => $nbErreur,
            'lignes' => $lignes,
        ];
    }
}
