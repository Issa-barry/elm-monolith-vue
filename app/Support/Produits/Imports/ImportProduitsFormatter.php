<?php

namespace App\Support\Produits\Imports;

use App\Models\ImportProduits;

/**
 * Sérialisation d'un import produits pour la liste (Index) et le détail (Show) — extrait de
 * ImportProduitsController::toRow()/toDetail(), partagé entre index() et show() (detail()
 * réutilise row() tel quel, jamais dupliqué).
 */
final class ImportProduitsFormatter
{
    public static function row(ImportProduits $i): array
    {
        return [
            'id' => $i->id,
            'fichier_original' => $i->fichier_original,
            'statut' => $i->statut->value,
            'statut_label' => $i->statut->label(),
            'nb_lignes_total' => $i->nb_lignes_total,
            'nb_lignes_creation' => $i->nb_lignes_creation,
            'nb_lignes_mise_a_jour' => $i->nb_lignes_mise_a_jour,
            'nb_lignes_inchange' => $i->nb_lignes_inchange,
            'nb_lignes_erreur' => $i->nb_lignes_erreur,
            'nb_produits_crees' => $i->nb_produits_crees,
            'nb_produits_mis_a_jour' => $i->nb_produits_mis_a_jour,
            'utilisateur' => $i->user ? trim("{$i->user->prenom} {$i->user->nom}") : null,
            'created_at' => $i->created_at?->format('d/m/Y H:i'),
            'termine_le' => $i->termine_le?->format('d/m/Y H:i'),
        ];
    }

    public static function detail(ImportProduits $i): array
    {
        return array_merge(self::row($i), [
            'peut_confirmer' => $i->estPret(),
            'rapport' => $i->rapport,
            'erreur_technique' => $i->erreur_technique,
        ]);
    }
}
