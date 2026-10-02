<?php

namespace App\Services\Commission;

use App\Models\EquipeLivraisonPartageCategorie;
use Carbon\CarbonInterface;

/**
 * Écriture versionnée du partage Livreur d'une équipe pour UNE catégorie et UN processus — seule
 * façon de remplacer un partage (enregistrement de l'équipe, transfert d'un livreur, publication
 * d'un brouillon de barème) : la version active est bornée (`effective_to` = date d'effet), la
 * nouvelle commence à cette même date, jamais de suppression ni de mise à jour en place — une
 * régénération historique résout ainsi toujours le partage réellement en vigueur à sa date.
 */
class PartageLivraisonVersionService
{
    /**
     * @param  array<string, int>  $montantsParLivreur  livreur_id => GNF/pack
     */
    public static function versionner(
        string $equipeId,
        string $processusId,
        string $categorieId,
        array $montantsParLivreur,
        CarbonInterface $dateEffet,
    ): void {
        $date = $dateEffet->toDateString();

        EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processusId)
            ->where('categorie_id', $categorieId)
            ->whereNull('effective_to')
            ->update(['effective_to' => $date]);

        foreach ($montantsParLivreur as $livreurId => $montant) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipeId,
                'processus_id' => $processusId,
                'categorie_id' => $categorieId,
                'livreur_id' => $livreurId,
                'part_pourcentage' => 0,
                'montant_unitaire' => (int) $montant,
                'effective_from' => $date,
                'effective_to' => null,
            ]);
        }
    }
}
