<?php

namespace App\Support\Sites;

/**
 * Formate le résultat d'analyse d'un import de sites (compteurs + lignes) — extrait de
 * SiteImportController::toResponse(), partagé entre l'aperçu (analyser) et la confirmation
 * (confirmer) puisque cette dernière ré-analyse toujours le fichier avant d'exécuter.
 */
final class SiteImportAnalyseFormatter
{
    public static function pour(array $analyse): array
    {
        $lignes = $analyse['lignes'];
        $nbErreur = count(array_filter($lignes, fn ($l) => $l['statut'] === 'erreur'));
        $nbExistant = count(array_filter($lignes, fn ($l) => $l['statut'] === 'existant'));
        $nbMiseAJour = count(array_filter($lignes, fn ($l) => $l['statut'] === 'mise_a_jour'));

        return [
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            'nb_nouveaux' => count($lignes) - $nbErreur - $nbExistant - $nbMiseAJour,
            'nb_existants' => $nbExistant,
            'nb_mises_a_jour' => $nbMiseAJour,
            'nb_erreurs' => $nbErreur,
            'lignes' => $lignes,
        ];
    }
}
