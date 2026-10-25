<?php

namespace App\Services\Commission;

use App\Models\Categorie;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     * @param  ?CarbonInterface  $droitsParrainDepuis  PARRAIN-024 : date de début des droits du parrain
     *                                                 en attente de la répartition de l'équipe (partage « À faire »)
     * @param  bool  $droitsEnAttenteTraites  l'appelant applique ou remplace une date en attente
     *                                        (modale Équipe, onglet Parrain) — refus sinon
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
        bool $confirmerPartParrainNulle = false,
        bool $remplacerPlanification = false,
        ?CarbonInterface $droitsParrainDepuis = null,
        bool $droitsEnAttenteTraites = false,
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

        // PARRAIN-023 : une répartition planifiée (date de début future) n'est jamais écrasée en
        // silence — seule la saisie de la part du parrain (onglet Parrain) peut la remplacer, explicitement.
        $planifiee = EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processusId)
            ->where('categorie_id', $categorieId)
            ->whereDate('effective_from', '>', Carbon::today()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereColumn('effective_to', '>', 'effective_from'))
            ->min('effective_from');
        if ($planifiee !== null && ! $remplacerPlanification) {
            throw ValidationException::withMessages([
                'partages' => sprintf(
                    'Une répartition planifiée prend effet le %s pour la catégorie %s : modifiez-la depuis l\'onglet Parrain du véhicule (date de début des droits) avant de changer l\'équipe ou sa répartition.',
                    Carbon::parse($planifiee)->format('d/m/Y'),
                    Categorie::whereKey($categorieId)->value('nom') ?? $categorieId,
                ),
            ]);
        }

        // PARRAIN-024 : des droits du parrain en attente (date passée saisie dans l'onglet Parrain)
        // ne s'appliquent qu'à la répartition faite dans la modale Équipe — jamais perdus en silence
        // par un transfert de livreur ou une reconfiguration.
        if ($parrainId !== null && ! $droitsEnAttenteTraites) {
            $enAttente = self::droitsEnAttente($equipeId, $processusId, $categorieId, $parrainId);
            if ($enAttente !== null) {
                throw ValidationException::withMessages([
                    'partages' => sprintf(
                        'La part du parrain est due depuis le %s pour la catégorie %s : faites d\'abord la répartition de l\'équipe (onglet Équipe du véhicule), ou changez la date dans l\'onglet Parrain.',
                        $enAttente->format('d/m/Y'),
                        Categorie::whereKey($categorieId)->value('nom') ?? $categorieId,
                    ),
                ]);
            }
        }

        $confirmeeAt = $parrainId !== null
            ? self::confirmationPartParrain($equipeId, $processusId, $categorieId, $parrainId, (int) $montantParrain, $confirmerPartParrainNulle)
            : null;

        $versions = fn () => EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processusId)
            ->where('categorie_id', $categorieId);

        if ($planifiee !== null) {
            // Remplacement explicite d'une planification : toute version future est neutralisée et
            // la version en vigueur aujourd'hui, que la planification bornait, est prolongée —
            // jamais de période sans répartition entre aujourd'hui et la nouvelle date d'effet.
            $aujourdhui = Carbon::today()->toDateString();
            $versions()
                ->whereDate('effective_from', '>', $aujourdhui)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereColumn('effective_to', '>', 'effective_from'))
                ->update(['effective_to' => DB::raw('effective_from')]);
            $versions()
                ->whereDate('effective_from', '<=', $aujourdhui)
                ->whereNotNull('effective_to')
                ->whereDate('effective_to', '>', $aujourdhui)
                ->update(['effective_to' => null]);
        }

        // Date d'effet antérieure à des versions existantes (PARRAIN-023, droits du parrain
        // antidatés) : ces versions sont neutralisées — bornées à leur propre début, jamais
        // supprimées (historique conservé). Sans effet pour une date d'effet du jour.
        $versions()
            ->whereDate('effective_from', '>', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereColumn('effective_to', '>', 'effective_from'))
            ->update(['effective_to' => DB::raw('effective_from')]);

        // Version en cours à la date d'effet : bornée à cette date.
        $versions()
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))
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
                'part_parrain_confirmee_at' => isset($ligne['parrain_id']) ? $confirmeeAt : null,
                'droits_parrain_depuis' => isset($ligne['parrain_id']) ? $droitsParrainDepuis?->toDateString() : null,
                'effective_from' => $date,
                'effective_to' => null,
            ]);
        }
    }

    /**
     * PARRAIN-024 : date de début des droits du parrain en attente sur la version en vigueur d'un
     * partage (null : aucune).
     */
    public static function droitsEnAttente(string $equipeId, string $processusId, string $categorieId, string $parrainId): ?Carbon
    {
        $date = EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processusId)
            ->where('categorie_id', $categorieId)
            ->where('parrain_id', $parrainId)
            ->whereNull('effective_to')
            ->whereNotNull('droits_parrain_depuis')
            ->value('droits_parrain_depuis');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    /**
     * PARRAIN-022 — date de décision de la part du parrain dans la nouvelle version : une part
     * positive vaut décision ; une part nulle n'est confirmée que sur demande explicite d'une
     * personne, ou reprise telle quelle si la version remplacée portait déjà une part nulle
     * confirmée pour CE parrain (modifier l'équipe ne redemande pas une décision déjà prise). Un
     * nouveau parrain, ou une ligne créée par le système, reste donc « à répartir ».
     */
    private static function confirmationPartParrain(string $equipeId, string $processusId, string $categorieId, string $parrainId, int $montant, bool $confirmer): ?Carbon
    {
        if ($montant > 0 || $confirmer) {
            return Carbon::now();
        }

        $precedente = EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processusId)
            ->where('categorie_id', $categorieId)
            ->where('parrain_id', $parrainId)
            ->whereNull('effective_to')
            ->first();

        return $precedente && (int) $precedente->montant_unitaire === 0
            ? $precedente->part_parrain_confirmee_at
            : null;
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
