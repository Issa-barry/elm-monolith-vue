<?php

namespace App\Services\Commission;

use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Écriture versionnée du partage Livreur d'une équipe pour UNE catégorie et UN processus — seule
 * façon de remplacer un partage (enregistrement de l'équipe, transfert d'un livreur, publication
 * d'un brouillon de barème, changement de parrain) : la version active est bornée
 * (`effective_to` = date d'effet), la nouvelle commence à cette même date, jamais de suppression
 * ni de mise à jour en place — une régénération historique résout ainsi toujours le partage
 * réellement en vigueur à sa date.
 *
 * Garde-fou du parrain (cf. docs/parrainage-vehicule.md) : la version active étant fermée en bloc,
 * un appelant qui oublierait la ligne du parrain l'effacerait. Toute nouvelle version d'un
 * processus qui rémunère le parrain d'un véhicule parrainé doit donc porter explicitement sa part
 * (0 GNF accepté) — jamais complétée ni recalculée ici — et aucune autre ne peut en porter.
 */
class PartageLivraisonVersionService
{
    /**
     * @param  array<string, int>  $montantsParLivreur  livreur_id => GNF/pack
     * @param  ?int  $montantParrain  part du parrain du véhicule, GNF/pack
     *
     * @throws ValidationException si la part du parrain manque, ou n'a pas lieu d'être
     */
    public static function versionner(
        string $equipeId,
        string $processusId,
        string $categorieId,
        array $montantsParLivreur,
        CarbonInterface $dateEffet,
        ?int $montantParrain = null,
    ): void {
        $parrainId = self::parrainRemunereId($equipeId, $processusId);

        if ($parrainId !== null && $montantParrain === null) {
            throw ValidationException::withMessages([
                'partages' => 'Ce véhicule a un parrain : sa part (0 GNF accepté) doit figurer dans la répartition de chaque catégorie. Refaites le partage complet de l\'équipe.',
            ]);
        }

        if ($parrainId === null && $montantParrain !== null) {
            throw ValidationException::withMessages([
                'partages' => 'Aucune part de parrain n\'est possible ici : le véhicule n\'a pas de parrain, ou ce processus ne le rémunère pas.',
            ]);
        }

        $date = $dateEffet->toDateString();

        EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processusId)
            ->where('categorie_id', $categorieId)
            ->whereNull('effective_to')
            ->update(['effective_to' => $date]);

        $lignes = collect($montantsParLivreur)->map(fn ($montant, $livreurId) => ['livreur_id' => $livreurId, 'montant' => $montant]);
        if ($parrainId !== null) {
            $lignes->push(['parrain_id' => $parrainId, 'montant' => $montantParrain]);
        }

        foreach ($lignes as $ligne) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipeId,
                'processus_id' => $processusId,
                'categorie_id' => $categorieId,
                'livreur_id' => $ligne['livreur_id'] ?? null,
                'parrain_id' => $ligne['parrain_id'] ?? null,
                'part_pourcentage' => 0,
                'montant_unitaire' => (int) $ligne['montant'],
                'effective_from' => $date,
                'effective_to' => null,
            ]);
        }
    }

    /** Parrain du véhicule de l'équipe si ce processus le rémunère, null sinon. */
    public static function parrainRemunereId(string $equipeId, string $processusId): ?string
    {
        $code = CommissionProcessus::whereKey($processusId)->value('code');
        if (! $code || ! CommissionProcessusDefaults::remunereLeParrain($code)) {
            return null;
        }

        return EquipeLivraison::with('vehicule')->find($equipeId)?->vehicule?->parrain_id;
    }
}
